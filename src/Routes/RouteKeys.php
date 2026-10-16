<?php

namespace StrictlyLaravel\Routes;

use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Reflector;
use Illuminate\Support\Str;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionProperty;
use StrictlyLaravel\Broadcasting\BroadcastChannels;
use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\Data\KeyOf;
use StrictlyLaravel\Data\QueryData;
use StrictlyLaravel\Data\RouteKeyOf;
use Throwable;

/**
 * Nominal model keys (Problems P-003, P-128). Wayfinder types a route parameter bound to a model structurally, as
 * `number | { id: number }`, and laravel-data a key property as a plain `number`, so any number, or any object with an
 * `id`, fits: an issue where a project is expected, or an issue's per-project `number` where its `id` is, type-checks.
 * Instead a model's key is branded with the model and the field it is a key of, `Key<'Issue', 'id', number>`, on both
 * sides of the call:
 *
 *  - a route parameter whose action type-hints the model (`show(Issue $issue)`) takes the key of the field the route
 *    binds by, `Key<'Issue', 'number', number>` or `{ number: Key<'Issue', 'number', number> }` (the Wayfinder template
 *    override, WayfinderTemplates::functionArguments());
 *  - a Data class built from the model (a static `from…(Issue $issue)` method, such as `fromModel()`) has its primary
 *    key (`id`) and the properties named after the binding fields the routes use for the model (`number` for
 *    `{issue}` bound by it) generated as that field's key (TypeScript\RouteKeyTypes);
 *  - a property declared `#[KeyOf(User::class)]` (its primary key, or `#[KeyOf(Issue::class, 'number')]`) or
 *    `#[RouteKeyOf(Project::class)]` (its route key name) is generated as that key, in input, query and output classes
 *    alike, so an input takes the keys the server sent;
 *  - a broadcast channel parameter whose callback type-hints the model (`fn (User $user, Project $project)`) binds it
 *    like a route, so `channel('projects.{project}', …)` takes the key too (Broadcasting\BroadcastChannels).
 *
 * So `show(issue)` takes an IssueData, its `number`, or a property declared the issue's route key, but not a
 * ProjectData, the issue's `id` or a plain number, and an input's `#[KeyOf(User::class)] $assigneeId` takes a member's
 * `id` but not an issue's. Feature tests check that every branded value the server sends is an existing key of its
 * model (Testing\RouteKeyChecks).
 */
final class RouteKeys
{
    /** @var array<string, ?class-string<Model>> */
    private array $models = [];

    /** @var array<class-string<Model>, string> Each model's primary key name. */
    private static array $keyNames = [];

    /**
     * @param  array<class-string<Model>, list<string>>  $fields  The binding fields the routes use for each model.
     */
    private function __construct(private readonly array $fields) {}

    public static function of(Router $router): self
    {
        $fields = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            foreach (self::boundParameters($route) as $name => $parameter) {
                $model = self::boundModel($parameter);

                if ($model !== null) {
                    $field = $route->bindingFieldFor($name);
                    $fields[$model][] = is_string($field) ? $field : self::routeKeyName($model);
                }
            }
        }

        // A broadcast channel binds a model like a route (Problem P-054): `channel('issues.{issue}', …)` takes its key.
        foreach (BroadcastChannels::bindings(app()) as $model => $names) {
            foreach ($names as $name) {
                $fields[$model][] = $name;
            }
        }

        return new self(array_map(fn (array $names): array => array_values(array_unique($names)), $fields));
    }

    /**
     * The model a route parameter is bound to: the class of the action parameter Wayfinder matches to it, when that is
     * an Eloquent model. Null for a plain parameter (`{token}`) or one bound to another UrlRoutable.
     *
     * @return class-string<Model>|null
     */
    public static function boundModel(?ReflectionParameter $parameter): ?string
    {
        $class = $parameter === null ? null : Reflector::getParameterClassName($parameter);

        return $class !== null && is_subclass_of($class, Model::class) ? $class : null;
    }

    /**
     * Problem P-002: route parameters that name a binding field (`{workspace:slug}`) but whose action doesn't type-hint
     * a model for them, so Wayfinder can't brand them and their route function takes any string. One description each,
     * such as `GET w/{workspace}: {workspace:slug} in App\Http\Controllers\WorkspaceController@show`.
     *
     * @return list<string>
     */
    public static function untypedBindings(Router $router): array
    {
        $untyped = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            foreach (self::boundParameters($route) as $name => $parameter) {
                $field = $route->bindingFieldFor($name);

                if ($parameter === null && is_string($field)) {
                    $untyped[] = sprintf(
                        '%s %s: {%s:%s} in %s',
                        implode('|', array_diff($route->methods(), ['HEAD'])),
                        $route->uri(),
                        $name,
                        $field,
                        $route->getActionName(),
                    );
                }
            }
        }

        return $untyped;
    }

    /**
     * The model (and the field) whose key a Data class property holds, if any: declared with #[KeyOf] or #[RouteKeyOf]
     * (in any Data class), or, in an output class built from a model, the property is named after the model's primary
     * key or a binding field the routes use for the model. Input and query classes hold what the user sent, so only a
     * declared key is branded there.
     */
    public function forProperty(string $dataClass, string $property): ?RouteKey
    {
        if (! class_exists($dataClass) || ! property_exists($dataClass, $property)) {
            return null;
        }

        $declared = self::declaredKey(new ReflectionProperty($dataClass, $property));

        if ($declared !== null || is_subclass_of($dataClass, InputData::class) || is_subclass_of($dataClass, QueryData::class)) {
            return $declared;
        }

        $model = $this->modelOf($dataClass);

        if ($model === null) {
            return null;
        }

        return $property === self::keyName($model) || in_array($property, $this->fields[$model] ?? [], true) ? new RouteKey($model, $property) : null;
    }

    /**
     * The key a property declares with #[KeyOf] (the model's primary key unless it names a field) or #[RouteKeyOf] (its
     * route key name unless it names a field).
     */
    public static function declaredKey(ReflectionProperty $property): ?RouteKey
    {
        foreach ($property->getAttributes() as $attribute) {
            $declared = match ($attribute->getName()) {
                KeyOf::class, RouteKeyOf::class => $attribute->newInstance(),
                default => null,
            };

            if ($declared instanceof KeyOf) {
                return new RouteKey($declared->model, $declared->field ?? self::keyName($declared->model));
            }

            if ($declared instanceof RouteKeyOf) {
                return new RouteKey($declared->model, $declared->field ?? self::routeKeyName($declared->model));
            }
        }

        return null;
    }

    /**
     * The field a route binds each model by when it names none (its route key name), by brand: what
     * `RouteKey<'Issue', number>` names in TypeScript. Every model a route or broadcast channel binds.
     *
     * @return array<string, string>
     */
    public function routeKeyFields(): array
    {
        $fields = [];

        foreach (array_keys($this->fields) as $model) {
            $fields[class_basename($model)] ??= self::routeKeyName($model);
        }

        ksort($fields);

        return $fields;
    }

    /**
     * The properties of a Data class that hold a model key (forProperty()), by property name.
     *
     * @return array<string, RouteKey>
     */
    public function inDataClass(string $dataClass): array
    {
        $keys = [];

        foreach (class_exists($dataClass) ? (new ReflectionClass($dataClass))->getProperties(ReflectionProperty::IS_PUBLIC) : [] as $property) {
            $key = $property->isStatic() ? null : $this->forProperty($dataClass, $property->getName());

            if ($key !== null) {
                $keys[$property->getName()] = $key;
            }
        }

        return $keys;
    }

    /**
     * The model a Data class is built from: the one model its static `from…()` methods take as their first parameter
     * (laravel-data calls them for `::from($project)`). Null when they take none, or more than one model.
     *
     * @param  class-string  $dataClass
     * @return class-string<Model>|null
     */
    private function modelOf(string $dataClass): ?string
    {
        if (array_key_exists($dataClass, $this->models)) {
            return $this->models[$dataClass];
        }

        $models = [];

        foreach ((new ReflectionClass($dataClass))->getMethods(ReflectionMethod::IS_STATIC | ReflectionMethod::IS_PUBLIC) as $method) {
            $type = str_starts_with($method->getName(), 'from') ? ($method->getParameters()[0] ?? null)?->getType() : null;

            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), Model::class)) {
                $models[$type->getName()] = true;
            }
        }

        return $this->models[$dataClass] = count($models) === 1 ? array_key_first($models) : null;
    }

    /**
     * Each route parameter, with the action parameter that binds it (type-hinted with a UrlRoutable; null when there is
     * none), matched by name as Wayfinder matches them.
     *
     * @return array<string, ReflectionParameter|null>
     */
    private static function boundParameters(Route $route): array
    {
        try {
            $signature = array_values(array_filter(
                $route->signatureParameters(['subClass' => UrlRoutable::class]),
                fn (mixed $parameter): bool => $parameter instanceof ReflectionParameter,
            ));
        } catch (Throwable) {
            // An action Wayfinder can't reflect either.
            return [];
        }

        $bound = [];

        foreach ($route->parameterNames() as $name) {
            if (! is_string($name)) {
                continue;
            }

            $bound[$name] = array_find(
                $signature,
                fn (ReflectionParameter $parameter): bool => Str::snake($parameter->getName()) === Str::snake($name),
            );
        }

        return $bound;
    }

    /**
     * @param  class-string<Model>  $model
     */
    private static function routeKeyName(string $model): string
    {
        return (new $model)->getRouteKeyName();
    }

    /**
     * @param  class-string<Model>  $model
     */
    private static function keyName(string $model): string
    {
        return self::$keyNames[$model] ??= (new $model)->getKeyName();
    }
}
