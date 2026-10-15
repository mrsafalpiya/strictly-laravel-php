<?php

namespace StrictlyLaravel\Routes;

use Closure;
use Illuminate\Routing\Route;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use StrictlyLaravel\Data\InputData;

/**
 * The input Data class of a controller action: the payload its route is branded with (WayfinderTemplates), the class
 * its validation errors are checked against (ContractRecorder) and what makes its route a form endpoint (Contract
 * Coverage). It is the action's first parameter typed with an InputData subclass.
 *
 * An action of a controller the app doesn't own (Fortify's) takes no input class: its payload is the one
 * config/strictly.php declares for it (VendorDeclarations, Problem P-025), which wins over its parameters.
 */
final class RouteInput
{
    /**
     * @return class-string<InputData>|null
     */
    public static function ofAction(string $controller, string $method): ?string
    {
        $class = ltrim($controller, '\\');

        if ($class === 'Closure' || ! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        return VendorDeclarations::input($class, $method) ?? self::firstInputParameter(new ReflectionMethod($class, $method));
    }

    /**
     * The input of the action a route runs, a controller method or a closure.
     *
     * @return class-string<InputData>|null
     */
    public static function ofRoute(Route $route): ?string
    {
        $uses = $route->getAction('uses');

        if ($uses instanceof Closure) {
            return self::firstInputParameter(new ReflectionFunction($uses));
        }

        if (! is_string($uses)) {
            return null;
        }

        // An invokable controller's action name has no `@method`.
        $method = str_contains($route->getActionName(), '@') ? $route->getActionMethod() : '__invoke';

        return self::ofAction($route->getControllerClass() ?? '', $method);
    }

    /**
     * @return class-string<InputData>|null
     */
    private static function firstInputParameter(ReflectionFunctionAbstract $action): ?string
    {
        foreach ($action->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), InputData::class)) {
                return $type->getName();
            }
        }

        return null;
    }
}
