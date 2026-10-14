<?php

namespace StrictlyLaravel\Routes;

use Illuminate\Contracts\View\Factory;
use Laravel\Wayfinder\GenerateCommand;
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
 * The package's resources/wayfinder directory is prepended to the `wayfinder` view namespace, so its `method`
 * template is used instead of Wayfinder's. Rather than a copy that would drift from upstream, it renders Wayfinder's
 * own template and adds the brands to each return type (method()); a template it doesn't override (such as
 * `function-arguments`) is still Wayfinder's.
 *
 * A Type Boundary that brands routes with more (a JSON endpoint's response, a GET action's query) adds its brand in
 * brands(); one that changes the route's arguments adds its own template to resources/wayfinder.
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

        $brands = self::brands($controller, ($data['isInvokable'] ?? false) === true ? '__invoke' : $method);

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
     * The TypeScript intersected into the return type of the action's route functions, or '' when there is none:
     * `& WithInput<StoreProjectData>` for an action taking an InputData.
     */
    public static function brands(string $controller, string $method): string
    {
        $brands = '';
        $input = RouteInput::ofAction($controller, $method);

        if ($input !== null) {
            $brands .= sprintf(" & import('%s').WithInput<%s>", self::TYPES_MODULE, self::generatedType($input));
        }

        return $brands;
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
