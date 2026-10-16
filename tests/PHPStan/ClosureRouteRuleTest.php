<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use StrictlyLaravel\PHPStan\ClosureRouteRule;

/**
 * @extends StrictlyRuleTestCase<ClosureRouteRule>
 */
final class ClosureRouteRuleTest extends StrictlyRuleTestCase
{
    protected function getRule(): Rule
    {
        return new ClosureRouteRule($this->createReflectionProvider());
    }

    public function test_closure_routes_take_no_input_or_query_class_and_return_no_data_class(): void
    {
        $tip = 'Move the route to a controller action (a public method of a class in App\Http\Controllers): Wayfinder brands a route function with the input class, query class and response Data class of the controller action it runs.';
        $formRequestTip = 'Move the route to a controller action that takes an input class instead (a final Data class extending StrictlyLaravel\Data\InputData, with the FormRequest\'s rules() and authorize()): Wayfinder brands a route function with the input class of the controller action it runs.';
        $payload = static fn (string $route, string $class, string $kind, int $line, ?string $tipOverride = null): array => [
            "The closure route {$route} takes {$class}, {$kind}, but Wayfinder types a route function's payload only from a controller action: no typed form can send it.",
            $line,
            $tipOverride ?? $tip,
        ];
        $query = static fn (string $route, string $class, int $line): array => [
            "The closure route {$route} takes {$class}, a query class, but Wayfinder types a route function's query option only from a controller action: no route function can send its keys.",
            $line,
            $tip,
        ];
        $response = static fn (string $route, int $line): array => [
            "The closure route {$route} returns App\\Data\\ClosureRoutes\\StatsData, but Wayfinder types a route function's response only from a controller action: useRouteHttp() types its result unknown.",
            $line,
            $tip,
        ];

        $this->analyse([__DIR__.'/data/closure-routes.php'], [
            $payload('POST projects', 'App\Data\ClosureRoutes\StoreProjectData', 'an input class', 61),
            $query('GET projects', 'App\Data\ClosureRoutes\ProjectFiltersData', 62),
            $payload('PATCH stats', 'App\Data\ClosureRoutes\StatsData', 'a Data class', 65),
            $payload('POST imports', 'App\Http\Requests\ClosureRoutes\StoreProjectRequest', 'a FormRequest', 66, $formRequestTip),
            $response('GET stats', 67),
            $response('ANY nullable-stats', 68),
            $response('GET inferred-stats', 69),
            $payload('GET|POST search', 'App\Data\ClosureRoutes\StoreProjectData', 'an input class', 70),
            $response('GET|POST search', 70),
            $payload('PUT projects', 'App\Data\ClosureRoutes\StoreProjectData', 'an input class', 73),
            $response('GET totals', 79),
        ]);
    }
}
