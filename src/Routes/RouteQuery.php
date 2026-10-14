<?php

namespace StrictlyLaravel\Routes;

use ReflectionMethod;
use ReflectionNamedType;
use StrictlyLaravel\Data\QueryData;

/**
 * The query Data class of a controller action: the type its route function's `query` option takes
 * (WayfinderTemplates). It is the action's first parameter typed with a QueryData subclass.
 *
 * This is the one place an action's query string is looked up, so a declared query class for a GET the app doesn't
 * own (a vendor controller's) belongs here too.
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

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), QueryData::class)) {
                return $type->getName();
            }
        }

        return null;
    }
}
