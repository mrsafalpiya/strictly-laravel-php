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
use StrictlyLaravel\Data\QueryData;
use StrictlyLaravel\Data\RouteKeyOf;
use Throwable;

/**
 * Nominal route keys (Problem P-003). Wayfinder types a route parameter bound to a model structurally, as
 * `number | { id: number }`, so any number, or any object with an `id`, fits: an issue where a project is expected
 * type-checks. Instead a model's route key is branded with the model on both sides of the call:
 *
 *  - a route parameter whose action type-hints the model (`show(Project $project)`) takes `RouteKey<'Project', number>`
 *    or `{ id: RouteKey<'Project', number> }` (the Wayfinder template override, WayfinderTemplates::functionArguments());
 *  - a Data class built from the model (a static `from…(Project $project)` method, such as `fromModel()`) has the
 *    properties named after the binding fields the routes use for it (`id`, or `slug` for `{project:slug}`) generated
 *    as `RouteKey<'Project', …>`, and so does a property declared `#[RouteKeyOf(Project::class)]` (TypeScript\RouteKeyTypes);
 *  - a broadcast channel parameter whose callback type-hints the model (`fn (User $user, Project $project)`) binds it
 *    like a route, so `channel('projects.{project}', …)` takes the key too (Broadcasting\BroadcastChannels).
 *
 * So `show(project)` takes a ProjectData, its `id`, or an issue's declared `project_id`, but not an IssueData, its `id`
 * or a plain number. Feature tests check that every branded value sent is an existing key of its model
 * (Testing\RouteKeyChecks). Input and query classes are never branded: they hold what the user sent.
 */
final class RouteKeys
{
    /** @var array<string, ?class-string<Model>> */
    private array $models = [];

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
     * The model (and the column) whose route key a Data class property holds, if any: declared with #[RouteKeyOf], or
     * the property is named after a binding field the routes use for the model the class is built from.
     */
    public function forProperty(string $dataClass, string $property): ?RouteKey
    {
        if (! class_exists($dataClass) || ! property_exists($dataClass, $property) || is_subclass_of($dataClass, InputData::class) || is_subclass_of($dataClass, QueryData::class)) {
            return null;
        }

        $declared = (new ReflectionProperty($dataClass, $property))->getAttributes(RouteKeyOf::class)[0] ?? null;

        if ($declared !== null) {
            $key = $declared->newInstance();

            return new RouteKey($key->model, $key->field ?? self::routeKeyName($key->model));
        }

        $model = $this->modelOf($dataClass);

        return $model !== null && in_array($property, $this->fields[$model] ?? [], true) ? new RouteKey($model, $property) : null;
    }

    /**
     * The properties of a Data class that hold a route key (forProperty()), by property name.
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
}
