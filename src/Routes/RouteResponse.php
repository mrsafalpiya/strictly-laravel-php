<?php

namespace StrictlyLaravel\Routes;

use Closure;
use Illuminate\Routing\Route;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use Spatie\LaravelData\Data;
use StrictlyLaravel\Inertia\Page;

/**
 * The response Data class of a JSON endpoint (Problem P-048): the declared return type of a controller action (or
 * route closure) that is a Data class and not a Page. It is what the route is branded with (WayfinderTemplates), so
 * `useRouteHttp(route, init)` types its result, what its recorded JSON responses are checked against
 * (ContractRecorder) and what makes the route a JSON endpoint (Contract Coverage, JsonEndpoints).
 *
 *     public function search(SearchQueryData $data): SearchResultsData
 *
 * An action of a controller the app doesn't own (Fortify's two-factor endpoints) declares no return type: its response
 * is the one config/strictly.php declares for it (VendorDeclarations, Problem P-050), a Data class or, for JSON that
 * isn't one object, a TypeScript type such as `'string[]'` (typeScript() gives either as TypeScript). A nullable or
 * union return type declares no response: the route's result stays `unknown`.
 */
final class RouteResponse
{
    /**
     * @return class-string<Data>|string|null a Data class, or a declared TypeScript type
     */
    public static function ofAction(string $controller, string $method): ?string
    {
        $class = ltrim($controller, '\\');

        if ($class === 'Closure' || ! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        return VendorDeclarations::response($class, $method) ?? self::declaredData(new ReflectionMethod($class, $method));
    }

    /**
     * The TypeScript of a response ofAction() or ofRoute() found: the generated type of a Data class
     * (`import('@/generated').SearchResultsData`), or a declared TypeScript type as it is.
     */
    public static function typeScript(string $response): string
    {
        return VendorDeclarations::isTypeScript($response) ? $response : WayfinderTemplates::generatedType($response);
    }

    /**
     * The Data class of a response, or null when it is declared as a TypeScript type, whose shapes Contract Coverage
     * can't observe (Problem P-051).
     *
     * @return class-string<Data>|null
     */
    public static function dataClass(string $response): ?string
    {
        return ! VendorDeclarations::isTypeScript($response) && is_subclass_of($response, Data::class) ? $response : null;
    }

    /**
     * The response of the action a route runs, a controller method or a closure.
     *
     * @return class-string<Data>|string|null a Data class, or a declared TypeScript type
     */
    public static function ofRoute(Route $route): ?string
    {
        $uses = $route->getAction('uses');

        if ($uses instanceof Closure) {
            return self::declaredData(new ReflectionFunction($uses));
        }

        if (! is_string($uses)) {
            return null;
        }

        // An invokable controller's action name has no `@method`.
        $method = str_contains($route->getActionName(), '@') ? $route->getActionMethod() : '__invoke';

        return self::ofAction($route->getControllerClass() ?? '', $method);
    }

    /**
     * @return class-string<Data>|null
     */
    private static function declaredData(ReflectionFunctionAbstract $action): ?string
    {
        $type = $action->getReturnType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || $type->allowsNull()) {
            return null;
        }

        $class = $type->getName();

        return is_subclass_of($class, Data::class) && ! is_subclass_of($class, Page::class) ? $class : null;
    }
}
