<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use StrictlyLaravel\PHPStan\InputReadRule;

/**
 * @extends RuleTestCase<InputReadRule>
 */
final class InputReadRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new InputReadRule;
    }

    public function test_controllers_read_input_only_through_an_input_or_query_class(): void
    {
        $tip = 'Take an input class on the action (a final Data class extending StrictlyLaravel\Data\InputData) and read the field from it: it types the route\'s form and its validation errors. A GET\'s query string goes in a query class (extending StrictlyLaravel\Data\QueryData) instead.';
        $error = static fn (string $method, string $with, int $line): array => [
            "App\\Http\\Controllers\\InputReads\\ProjectController::{$method}() reads input from the request with {$with}, where nothing types it: a typed form sends only the fields of its action's input class.",
            $line,
            $tip,
        ];
        $accessors = static fn (int $line, string ...$methods): array => array_map(
            static fn (string $method): array => $error('accessors', "{$method}()", $line),
            $methods,
        );

        $this->analyse([__DIR__.'/data/input-reads.php'], [
            $error('store', 'input()', 40),
            ...$accessors(46, 'all', 'keys', 'only', 'except', 'get'),
            ...$accessors(47, 'post', 'json', 'str', 'string'),
            ...$accessors(48, 'integer', 'float', 'boolean', 'date'),
            ...$accessors(49, 'enum', 'array', 'collect'),
            ...$accessors(50, 'has', 'filled', 'missing', 'file'),
            ...$accessors(51, 'hasFile', 'allFiles', 'validate'),
            $error('properties', '->name', 57),
            $error('properties', "['name']", 57),
            $error('properties', '->request', 57),
            $error('properties', '->files', 57),
            $error('helpers', 'Request::input()', 62),
            $error('helpers', 'request()', 62),
            $error('formRequest', 'validated()', 87),
            $error('closure', 'input()', 92),
            $error('filter', 'input()', 97),
        ]);
    }
}
