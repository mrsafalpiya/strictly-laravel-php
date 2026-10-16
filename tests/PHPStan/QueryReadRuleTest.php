<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use StrictlyLaravel\PHPStan\QueryReadRule;

/**
 * @extends StrictlyRuleTestCase<QueryReadRule>
 */
final class QueryReadRuleTest extends StrictlyRuleTestCase
{
    protected function getRule(): Rule
    {
        return new QueryReadRule;
    }

    public function test_controllers_read_the_query_string_only_through_a_query_class(): void
    {
        $tip = 'Take a query class on the action (a final Data class extending StrictlyLaravel\Data\QueryData) and read the key from it: it types the route function\'s query option and falls back to its default on an invalid value.';
        $message = fn (string $method, string $read = 'query()'): string => "App\\Http\\Controllers\\Queries\\DashboardController::{$method}() reads the query string from the request with {$read}, where nothing types it: a route function sends only the keys of its action's query class.";

        $this->analyse([__DIR__.'/data/query-reads.php'], [
            [$message('index'), 24, $tip],
            [$message('all'), 29, $tip],
            [$message('all', '->query'), 29, $tip],
            [$message('all'), 29, $tip],
            [$message('facade', 'Request::query()'), 34, $tip],
            [$message('closure'), 44, $tip],
            [$message('filter'), 49, $tip],
        ]);
    }

    public function test_closure_routes_read_no_query_string(): void
    {
        $this->analyse([__DIR__.'/data/closure-route-reads.php'], [
            [
                "A closure route reads the query string from the request with query(), where nothing types it: a route function sends only the keys of a controller action's query class.",
                10,
                'Move the route to a controller action that takes a query class (a final Data class extending StrictlyLaravel\Data\QueryData) and read the key from it: Wayfinder types a route function\'s query option with the query class of the controller action it runs, never with a closure\'s.',
            ],
        ]);
    }
}
