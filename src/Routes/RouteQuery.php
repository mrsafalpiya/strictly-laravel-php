<?php

namespace StrictlyLaravel\Routes;

use Illuminate\Routing\Route;
use ReflectionMethod;
use ReflectionNamedType;
use StrictlyLaravel\Data\QueryData;

/**
 * The query Data class of a controller action: the type its route function's `query` option takes
 * (WayfinderTemplates). It is the action's first parameter typed with a QueryData subclass. A route without one (a
 * closure has none) takes no query string (Problem P-106).
 *
 * A GET of a controller the app doesn't own (Fortify's password reset link) takes no query class: its query class is
 * the one config/strictly.php declares for it (VendorDeclarations, Problem P-025), which wins over its parameters.
 */
final class RouteQuery
{
    /**
     * @return class-string<QueryData>|null
     */
    public static function ofAction(string $controller, string $method): ?string
    {
        $class = ltrim($controller, '\\');

        if ($class === 'Closure' || ! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        $declared = VendorDeclarations::query($class, $method);

        if ($declared !== null) {
            return $declared;
        }

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), QueryData::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    /**
     * The query class of the controller action a route runs (closures have none).
     *
     * @return class-string<QueryData>|null
     */
    public static function ofRoute(Route $route): ?string
    {
        if (! is_string($route->getAction('uses'))) {
            return null;
        }

        // An invokable controller's action name has no `@method`.
        $method = str_contains($route->getActionName(), '@') ? $route->getActionMethod() : '__invoke';

        return self::ofAction($route->getControllerClass() ?? '', $method);
    }
}
