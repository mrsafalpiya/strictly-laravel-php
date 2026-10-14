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
 * This is the one place a route's response is looked up, so a declared response for a controller the app doesn't own
 * (Problem P-050) belongs here too. A nullable or union return type declares no response: the route's result stays
 * `unknown`.
 */
final class RouteResponse
{
    /**
     * @return class-string<Data>|null
     */
    public static function ofAction(string $controller, string $method): ?string
    {
        $class = ltrim($controller, '\\');

        if ($class === 'Closure' || ! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        return self::declaredData(new ReflectionMethod($class, $method));
    }

    /**
     * The response of the action a route runs, a controller method or a closure.
     *
     * @return class-string<Data>|null
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
