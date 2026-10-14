<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use StrictlyLaravel\PHPStan\QueryDefaultRule;

/**
 * @extends RuleTestCase<QueryDefaultRule>
 */
final class QueryDefaultRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new QueryDefaultRule;
    }

    public function test_query_properties_default_to_what_an_absent_key_means(): void
    {
        $tip = 'An absent query key means "no filter": give the property the default that means that (null for a nullable property, [] for a list).';

        $this->analyse([__DIR__.'/data/query-defaults.php'], [
            ['App\Data\Queries\IssueFiltersData::$sort has no default, so a query string without "sort" can\'t be parsed: the request fails with a 500.', 25, $tip],
            ['App\Data\Queries\IssueFiltersData::$assignee is nullable, so it must default to null: a route function leaves a null query value out of the URL, and the server would read the default instead.', 27, $tip],
            ['App\Data\Queries\IssueFiltersData::$owners is a list, so it must default to []: a route function leaves an empty list out of the URL, and the server would read the default instead.', 29, $tip],
        ]);
    }
}
