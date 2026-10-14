<?php

namespace StrictlyLaravel\Routes;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use StrictlyLaravel\Data\InputData;

/**
 * The app's form endpoints: the routes whose action takes an input Data class (RouteInput). Each is named the same way
 * by the Contracts that record a request to it (ContractRecorder) and by Contract Coverage, which reports one no
 * feature test submits to: `POST /projects/{project}/issues`.
 */
final class FormEndpoints
{
    /**
     * Every form endpoint the router has, by name.
     *
     * @return array<string, class-string<InputData>>
     */
    public static function all(Router $router): array
    {
        $endpoints = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $input = RouteInput::ofRoute($route);

            if ($input !== null) {
                $endpoints[self::name($route)] = $input;
            }
        }

        ksort($endpoints);

        return $endpoints;
    }

    /** The method and URI pattern of a route: `PATCH /projects/{project}`. */
    public static function name(Route $route): string
    {
        $methods = array_values(array_diff($route->methods(), ['HEAD']));

        return ($methods[0] ?? 'GET').' /'.ltrim($route->uri(), '/');
    }
}
