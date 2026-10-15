<?php

// The framework's routing classes, for the tests of the rule about closure routes: the root dev environment has no
// laravel/framework. Its composer.json classmaps this file, since PHPStan's rule-test kit finds classes only through
// the autoloader. As in the framework, the facade and the registrar declare their route methods with @method.

namespace Illuminate\Routing;

class Route
{
    public function name(string $name): static
    {
        return $this;
    }
}

class Router
{
    public function get(string $uri, mixed $action = null): Route
    {
        return new Route;
    }

    public function post(string $uri, mixed $action = null): Route
    {
        return new Route;
    }

    /**
     * @param  array<string>|string  $methods
     */
    public function match(array|string $methods, string $uri, mixed $action = null): Route
    {
        return new Route;
    }

    public function group(mixed $attributes, mixed $routes): static
    {
        return $this;
    }
}

/**
 * @method Route get(string $uri, \Closure|array|string|null $action = null)
 * @method Route post(string $uri, \Closure|array|string|null $action = null)
 * @method Route put(string $uri, \Closure|array|string|null $action = null)
 * @method Route delete(string $uri, \Closure|array|string|null $action = null)
 */
class RouteRegistrar
{
    public function group(\Closure|array|string $callback): static
    {
        return $this;
    }
}

namespace Illuminate\Support\Facades;

/**
 * @method static \Illuminate\Routing\Route get(string $uri, array|string|callable|null $action = null)
 * @method static \Illuminate\Routing\Route post(string $uri, array|string|callable|null $action = null)
 * @method static \Illuminate\Routing\Route patch(string $uri, array|string|callable|null $action = null)
 * @method static \Illuminate\Routing\Route any(string $uri, array|string|callable|null $action = null)
 * @method static \Illuminate\Routing\Route match(array|string $methods, string $uri, array|string|callable|null $action = null)
 * @method static \Illuminate\Routing\Route fallback(array|string|callable|null $action)
 * @method static \Illuminate\Routing\RouteRegistrar middleware(array|string|null $middleware)
 * @method static \Illuminate\Routing\Router group(array $attributes, \Closure|array|string $routes)
 * @method static void bind(string $key, string|callable $binder)
 */
class Route {}
