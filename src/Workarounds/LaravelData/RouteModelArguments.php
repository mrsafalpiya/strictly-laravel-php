<?php

namespace StrictlyLaravel\Workarounds\LaravelData;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Routing\Route;
use LogicException;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Problem P-126: laravel-data calls an input's `authorize()` and `rules()` through the container, which knows nothing
 * of the route's bound parameters, so `authorize(Comment $comment)` would get a new, empty Comment. This gives each
 * model-typed parameter of such a method the model the current route binds to the parameter of the same name, as
 * named arguments for the container's call. Every other parameter still resolves through the container.
 *
 * It never falls back to an empty model: a model parameter the route has no parameter of that name for, a route
 * parameter that isn't bound to a model of that type (the action doesn't take it, so the route left it a string), or
 * a call outside a request's route (`validate()` from a command or a unit test) throws, naming the parameter.
 * A nullable model parameter receives null for an optional route parameter the URL left out.
 *
 * Used by AuthorizeWithRouteModels and RulesWithRouteModels, for InputData only.
 */
final class RouteModelArguments
{
    /**
     * The route's bound models for the model-typed parameters of a static method, keyed by parameter name.
     *
     * @return array<string, ?Model>
     */
    public static function of(string $class, string $method): array
    {
        $arguments = [];

        foreach ((new ReflectionMethod($class, $method))->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && ! $type->isBuiltin() && is_a($type->getName(), Model::class, true)) {
                $arguments[$parameter->getName()] = self::boundModel("{$class}::{$method}()", $parameter, $type);
            }
        }

        return $arguments;
    }

    private static function boundModel(string $method, ReflectionParameter $parameter, ReflectionNamedType $type): ?Model
    {
        $name = $parameter->getName();
        $taking = "{$method} takes {$type->getName()} \${$name}";
        $route = request()->route();

        if (! $route instanceof Route) {
            throw new LogicException("{$taking}, the model the current route binds to {{$name}}, but there is no current route. Validate this input through a request to its route (a feature test), not with validate() or validateAndCreate() outside one.");
        }

        if (! in_array($name, $route->parameterNames(), true)) {
            $names = array_map(fn (string $name): string => "{{$name}}", $route->parameterNames());

            throw new LogicException("{$taking}, but the route {$route->uri()} has no {{$name}} parameter. A model parameter of an input's authorize() or rules() receives the route's bound model of the same name".($names === [] ? '.' : ': rename it to one of '.implode(', ', $names).'.'));
        }

        $value = $route->parameter($name);

        if ($value === null && $type->allowsNull()) {
            return null;
        }

        if (! $value instanceof Model || ! is_a($value, $type->getName())) {
            $actual = is_string($value) || is_int($value) ? get_debug_type($value)." '{$value}'" : get_debug_type($value);

            throw new LogicException("{$taking}, but the route {$route->uri()} binds {{$name}} to {$actual}, not a {$type->getName()}. The route binds a model when its action takes it ({$type->getName()} \${$name}) and the SubstituteBindings middleware runs on it.");
        }

        return $value;
    }
}
