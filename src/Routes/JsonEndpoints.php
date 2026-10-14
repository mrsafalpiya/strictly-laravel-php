<?php

namespace StrictlyLaravel\Routes;

use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Spatie\LaravelData\Data;

/**
 * The app's JSON endpoints: the routes whose action returns a Data class (RouteResponse). Each is named the same way
 * by the Contracts that record its responses (ContractRecorder) and by Contract Coverage, which reports one no feature
 * test calls: `GET /search/stats`.
 */
final class JsonEndpoints
{
    /**
     * Every JSON endpoint the router has, by name, with the Data class it responds with.
     *
     * @return array<string, class-string<Data>>
     */
    public static function all(Router $router): array
    {
        $endpoints = [];

        foreach ($router->getRoutes()->getRoutes() as $route) {
            $response = RouteResponse::ofRoute($route);

            if ($response !== null) {
                $endpoints[self::name($route)] = $response;
            }
        }

        ksort($endpoints);

        return $endpoints;
    }

    /** The method and URI pattern of a route, named as form endpoints are: `GET /search/stats`. */
    public static function name(Route $route): string
    {
        return FormEndpoints::name($route);
    }
}
