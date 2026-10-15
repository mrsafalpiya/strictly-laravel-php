<?php

namespace StrictlyLaravel\Routes;

use Illuminate\Contracts\View\Factory;
use Illuminate\Support\Collection;
use Laravel\Wayfinder\GenerateCommand;
use Laravel\Wayfinder\Parameter;
use ReflectionClass;

/**
 * Wayfinder template overrides: every route function Wayfinder generates (`@/actions/…` and `@/routes/…`, and their
 * `.form()` variants) has its return type intersected with the brands of its controller action, so the React helpers
 * read the payload from the route itself:
 *
 *     public function store(StoreProjectData $data)   (an InputData)
 *       → store(): RouteDefinition<'post'> & WithInput<StoreProjectData>
 *       → useRouteForm(store(), defaults), submit(store(), data): data and errors typed by StoreProjectData
 *
 *     public function index(ProjectFiltersData $filters)   (a QueryData)
 *       → index(options?: QueryOptions<ProjectFiltersData>): `index({ query: { sort: 'name' } })` typed by it
 *
 *     public function edit()   (no QueryData, or a closure)
 *       → edit(options?: NoQueryOptions): `edit({ query: { tab: 'password' } })` fails
 *
 * The package's resources/wayfinder directory is prepended to the `wayfinder` view namespace, so its `method`
 * template is used instead of Wayfinder's. Rather than a copy that would drift from upstream, it renders Wayfinder's
 * own template and adds the brands to each return type (method()); a template it doesn't override (such as
 * `docblock`) is still Wayfinder's.
 *
 * Its `function-arguments` template does the same for the route parameters: a parameter bound to a model takes only
 * that model's route key (functionArguments(), Problem P-003):
 *
 *     public function show(Project $project)
 *       → show(args: { project: RouteKey<'Project', number> | { id: RouteKey<'Project', number> } } | …)
 *
 * A Type Boundary that brands routes with more (a JSON endpoint's response) adds its brand in brands(). The action's
 * query class, or its lack of one, retypes the last argument, `options`, in the rendered output (withQueryOptions()),
 * which functionArguments() leaves as Wayfinder writes it.
 */
final class WayfinderTemplates
{
    public const string DIRECTORY = __DIR__.'/../../resources/wayfinder';

    /** The npm package's types module that the brands come from. */
    public const string TYPES_MODULE = '@mrsafalpiya/strictly-laravel/routes';

    public static function register(Factory $view): void
    {
        $view->prependNamespace('wayfinder', self::DIRECTORY);
    }

    /**
     * Wayfinder's `method` template rendered with the template's data, with the action's brands added to every route
     * function's return type (`): RouteDefinition<'post'> => (` and `): RouteFormDefinition<'post'> => (`).
     *
     * @param  array<string, mixed>  $data
     */
    public static function method(array $data): string
    {
        $rendered = view()->file(self::upstream('method.blade.ts'), $data)->render();

        $controller = $data['controller'] ?? null;
        $method = $data['original_method'] ?? null;

        if (! is_string($controller) || ! is_string($method)) {
            return $rendered;
        }

        $action = ($data['isInvokable'] ?? false) === true ? '__invoke' : $method;
        $rendered = self::withQueryOptions($rendered, $controller, $action, $data['options'] ?? null);
        $brands = self::brands($controller, $action);

        if ($brands === '') {
            return $rendered;
        }

        return (string) preg_replace(
            '/\): (RouteDefinition|RouteFormDefinition)<([^<>\n]+)> => \(/',
            '): $1<$2>'.str_replace(['\\', '$'], ['\\\\', '\\$'], $brands).' => (',
            $rendered,
        );
    }

    /**
     * Wayfinder's `function-arguments` template rendered with the template's data, with every route parameter bound to
     * an Eloquent model (its action type-hints `Project $project`) typed as that model's route key,
     * `RouteKey<'Project', number>` (RouteKeys), instead of any number or any object with an `id`. Everything after the
     * parameters (the `options` argument) is Wayfinder's.
     *
     * @param  array<string, mixed>  $data
     */
    public static function functionArguments(array $data): string
    {
        $rendered = view()->file(self::upstream('function-arguments.blade.ts'), $data)->render();

        $parameters = $data['parameters'] ?? null;
        $args = $data['args'] ?? null;
        $options = $data['options'] ?? null;

        if (! $parameters instanceof Collection || ! is_string($args) || ! is_string($options)) {
            return $rendered;
        }

        /** @var Collection<int, Parameter> $parameters */
        if ($parameters->every(fn (Parameter $parameter): bool => RouteKeys::boundModel($parameter->bound) === null)) {
            return $rendered;
        }

        $rest = strrpos($rendered, "{$options}?:");

        return $rest === false ? $rendered : self::parameterArguments($parameters, $args).rtrim(substr($rendered, $rest));
    }

    /**
     * The route parameters argument as Wayfinder's template writes it (`args: { project: … } | [project: …] | …, `),
     * with each bound parameter branded.
     *
     * Problem P-004: it is written on one line. Wayfinder's output formatter collapses a multi-line argument list only
     * up to its first `)`, which here is inside `import(…)`, and leaves the rest unformatted.
     * Upstream: https://github.com/laravel/wayfinder/issues/328
     *
     * @param  Collection<int, Parameter>  $parameters
     */
    private static function parameterArguments(Collection $parameters, string $args): string
    {
        $type = function (Parameter $parameter): string {
            $model = RouteKeys::boundModel($parameter->bound);
            $type = $model === null
                ? $parameter->types
                : sprintf("import('%s').RouteKey<'%s', %s>", self::TYPES_MODULE, class_basename($model), $parameter->types);

            return $parameter->key === null ? $type : sprintf('%s | { %s: %s }', $type, $parameter->key, $type);
        };

        $first = $parameters->first();

        return sprintf(
            '%s%s: { %s } | [%s]%s, ',
            $args,
            $parameters->every(fn (Parameter $parameter): bool => $parameter->optional) ? '?' : '',
            $parameters->map(fn (Parameter $parameter): string => $parameter->name.($parameter->optional ? '?' : '').': '.$type($parameter))->implode(', '),
            $parameters->map(fn (Parameter $parameter): string => $parameter->safeName().': '.$type($parameter))->implode(', '),
            $parameters->count() === 1 && $first !== null ? ' | '.$type($first) : '',
        );
    }

    /**
     * The TypeScript intersected into the return type of the action's route functions, or '' when there is none:
     * `& WithInput<StoreProjectData>` for an action taking an InputData, `& WithResponse<SearchResultsData>` for a JSON
     * endpoint (RouteResponse), and the same for a vendor action's declarations (VendorDeclarations): `& WithInput<LoginData>`,
     * `& WithResponse<{ svg: string; url: string } | []>`.
     */
    public static function brands(string $controller, string $method): string
    {
        $brands = '';
        $input = RouteInput::ofAction($controller, $method);

        if ($input !== null) {
            $brands .= sprintf(" & import('%s').WithInput<%s>", self::TYPES_MODULE, self::generatedType($input));
        }

        $response = RouteResponse::ofAction($controller, $method);

        if ($response !== null) {
            $brands .= sprintf(" & import('%s').WithResponse<%s>", self::TYPES_MODULE, RouteResponse::typeScript($response));
        }

        return $brands;
    }

    /**
     * Problem P-006: the route functions of an action that takes a query Data class (RouteQuery) type their `options`
     * argument by it, `options?: QueryOptions<ProjectFiltersData>` instead of Wayfinder's `RouteQueryOptions` (any
     * key, any value).
     *
     * Problem P-106: the route functions of every other route (an action without a query class, a closure, a vendor
     * action config/strictly.php declares no query class for) take `options?: NoQueryOptions`, which no query string
     * fits, so `dashboard({ query: { tab: 'stats' } })` fails `tsc`.
     *
     * Wayfinder writes that argument last in every route function (`function-arguments`), so this keeps working when
     * another template override changes the arguments before it.
     */
    private static function withQueryOptions(string $rendered, string $controller, string $method, mixed $options): string
    {
        if (! is_string($options) || $options === '') {
            return $rendered;
        }

        $query = RouteQuery::ofAction($controller, $method);

        return (string) preg_replace(
            '/\b'.preg_quote($options, '/').'\?: RouteQueryOptions\b/',
            $query === null
                ? sprintf("%s?: import('%s').NoQueryOptions", $options, self::TYPES_MODULE)
                : sprintf("%s?: import('%s').QueryOptions<%s>", $options, self::TYPES_MODULE, self::generatedType($query)),
            $rendered,
        );
    }

    /**
     * A reference to the generated type of a Data class, from any file: `import('@/generated').StoreProjectData`.
     *
     * @param  class-string  $class
     */
    public static function generatedType(string $class): string
    {
        return "import('@/generated').".class_basename($class);
    }

    private static function upstream(string $template): string
    {
        $source = (new ReflectionClass(GenerateCommand::class))->getFileName();

        return dirname((string) $source, 2).'/resources/'.$template;
    }
}
