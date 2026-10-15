<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use StrictlyLaravel\PHPStan\QueryReadRule;

/**
 * @extends RuleTestCase<QueryReadRule>
 */
final class QueryReadRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new QueryReadRule;
    }

    public function test_controllers_read_the_query_string_only_through_a_query_class(): void
    {
        $tip = 'Take a query class on the action (a final Data class extending StrictlyLaravel\Data\QueryData) and read the key from it: it types the route function\'s query option and falls back to its default on an invalid value.';
        $message = fn (string $method): string => "App\\Http\\Controllers\\Queries\\DashboardController::{$method}() reads the query string from the request, where nothing types it: a route function sends only the keys of its action's query class.";

        $this->analyse([__DIR__.'/data/query-reads.php'], [
            [$message('index'), 24, $tip],
            [$message('all'), 29, $tip],
            [$message('all'), 29, $tip],
            [$message('all'), 29, $tip],
            [$message('facade'), 34, $tip],
            [$message('closure'), 44, $tip],
            [$message('filter'), 49, $tip],
        ]);
    }
}
