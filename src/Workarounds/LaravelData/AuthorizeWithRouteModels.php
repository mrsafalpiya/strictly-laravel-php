<?php

namespace StrictlyLaravel\Workarounds\LaravelData;

use Illuminate\Auth\Access\AuthorizationException;
use Spatie\LaravelData\DataPipes\AuthorizedDataPipe;

/**
 * Problem P-126: laravel-data's AuthorizedDataPipe, but an input's `authorize()` receives the route's bound models
 * for its model-typed parameters (RouteModelArguments). InputData puts it in place of laravel-data's pipe.
 */
final class AuthorizeWithRouteModels extends AuthorizedDataPipe
{
    protected function ensureRequestIsAuthorized(string $class): void
    {
        $authorize = [$class, 'authorize'];

        if (is_callable($authorize) && app()->call($authorize, RouteModelArguments::of($class, 'authorize')) === false) {
            throw new AuthorizationException;
        }
    }
}
