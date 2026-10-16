<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use StrictlyLaravel\PHPStan\JsonResponseRule;

/**
 * @extends StrictlyRuleTestCase<JsonResponseRule>
 */
final class JsonResponseRuleTest extends StrictlyRuleTestCase
{
    protected function getRule(): Rule
    {
        return new JsonResponseRule($this->createReflectionProvider());
    }

    public function test_a_controller_action_answering_json_returns_one_data_class(): void
    {
        $untyped = 'Return one Data class that holds the value instead, such as RecentProjectsData with a list<ProjectData> property: it types the route\'s response, and every response a feature test gets is checked against it. See the strictly-add-json-endpoint skill.';
        $undeclared = 'Return one Data class on every path: put a value that may be missing in a nullable property (ProjectLookupData with a ?ProjectData property), and refuse a request with abort() or a validation error. See the strictly-add-json-endpoint skill.';
        $json = static fn (string $method, string $type, int $line): array => [
            "App\\Http\\Controllers\\JsonResponses\\ProjectController::{$method}() returns {$type}, which Laravel sends as JSON that nothing types: useRouteHttp() types the route's response unknown, and no Contract checks it.",
            $line,
            $untyped,
        ];
        $union = static fn (string $method, string $type, int $line): array => [
            "App\\Http\\Controllers\\JsonResponses\\ProjectController::{$method}() returns {$type}, a nullable or union type, which declares no response: useRouteHttp() types the route's response unknown, and no Contract checks it.",
            $line,
            $undeclared,
        ];

        $this->analyse([__DIR__.'/data/json-responses.php'], [
            $json('recent', 'array', 68),
            $json('feed', 'iterable', 73),
            $json('collection', 'Illuminate\Support\Collection', 78),
            $json('dataCollection', 'Spatie\LaravelData\DataCollection', 83),
            $json('paginated', 'Spatie\LaravelData\PaginatedDataCollection', 88),
            $json('cursor', 'Spatie\LaravelData\CursorPaginatedDataCollection', 93),
            $json('resource', 'App\Http\Resources\JsonResponses\ProjectResource', 95),
            $union('find', 'App\Data\JsonResponses\ProjectData|null', 97),
            $union('findOrRedirect', 'App\Data\JsonResponses\ProjectData|Illuminate\Http\RedirectResponse', 99),
            $json('listOrRedirect', 'array|Illuminate\Http\RedirectResponse', 101),
        ]);
    }
}
