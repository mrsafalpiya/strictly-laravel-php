---
name: strictly-add-json-endpoint
description: "Adds a JSON endpoint the Strictly Laravel way: an action that returns a Data class, called from React with useRouteHttp so the request and the response are typed by the route. Use when adding an endpoint that answers JSON instead of a page: autocomplete, availability checks, search, polling, stats."
---
# Add a JSON endpoint

The worked example adds `POST projects/check-name`, which tells a form whether a project name is free and suggests another when it isn't. An action whose declared return type is a Data class (not a Page class) is a JSON endpoint: laravel-data sends the object as JSON, and the route is branded with its type, so React gets the response type from the route.

## 1. Write the response class (and the input class, if it takes a payload)

```php title="app/Data/ProjectNameAvailabilityData.php"
final class ProjectNameAvailabilityData extends Data
{
    public function __construct(
        public bool $available,
        /** A free name to offer instead, when this one is taken. */
        public ?string $suggestion,
    ) {}
}
```

```php title="app/Data/CheckProjectNameData.php"
final class CheckProjectNameData extends InputData
{
    public function __construct(
        #[Max(80)]
        public string $name,
    ) {}
}
```

A GET endpoint that reads a query string takes a query class instead (skill `strictly-add-query-filters`); one that takes nothing has no input class.

## 2. Return it from the action, declared as the return type

```php title="app/Http/Controllers/ProjectController.php"
    public function checkName(CheckProjectNameData $data): ProjectNameAvailabilityData
    {
        if (! Project::query()->where('name', $data->name)->exists()) {
            return new ProjectNameAvailabilityData(available: true, suggestion: null);
        }

        $suffix = 2;

        while (Project::query()->where('name', "{$data->name} {$suffix}")->exists()) {
            $suffix++;
        }

        return new ProjectNameAvailabilityData(available: false, suggestion: "{$data->name} {$suffix}");
    }
```

```php title="routes/web.php"
    Route::post('projects/check-name', [ProjectController::class, 'checkName'])->name('projects.check-name');
```

Return one Data object from a controller action: a closure route that returns a Data class, or anything else Laravel sends as JSON (an array, a Collection, a model), fails `strictly.closureRoute`, since Wayfinder types a route function only from a controller action. The `strictly.jsonResponse` PHPStan rule rejects an action that answers JSON any other way: a list (`array`, even with `@return list<ProjectData>`), a Collection, a `DataCollection` or paginated collection, a model, and a nullable or union return type (`?ProjectData`, `ProjectData|RedirectResponse`), none of which types the route's response. Wrap a list in a Data class that holds it:

```php
final class RecentProjectsData extends Data
{
    /**
     * @param  list<ProjectData>  $projects
     */
    public function __construct(
        public array $projects,
    ) {}
}
```

Put a value that may be missing in a nullable property of the response class, and refuse a request with `abort()` or a validation error instead of returning another type.

## 3. Call it with `useRouteHttp`

`useRouteHttp(route, init)` takes the route's input as its initial data (leave `init` out when the action takes no input). `submit()` resolves `{ ok: true, response }` or, on a 422, `{ ok: false, errors }` keyed by the input:

```tsx title="resources/js/components/projects/project-name-field.tsx"
import { useRouteHttp } from '@mrsafalpiya/strictly-laravel/react';
import { useState } from 'react';
import { checkName } from '@/actions/App/Http/Controllers/ProjectController';
import InputError from '@/components/input-error';

export function ProjectNameField() {
    const http = useRouteHttp(checkName(), { name: '' });
    const [hint, setHint] = useState<string | null>(null);

    const check = async () => {
        const result = await http.submit();

        if (result.ok) {
            setHint(
                result.response.suggestion === null
                    ? null
                    : `Taken. Try "${result.response.suggestion}".`,
            );
        }
    };

    return (
        <div>
            <input
                value={http.data.name}
                onChange={(event) => http.setData('name', event.target.value)}
                onBlur={() => void check()}
            />
            <InputError message={http.errors.name} />
            {hint !== null && <p>{hint}</p>}
        </div>
    );
}
```

`http.response` holds the last successful response (`null` before one).

## 4. Call it in feature tests, once per response shape

Each nullable response field must come back both `null` and set, each list non-empty, or `ci:check:coverage` fails. A POST that returns a Data object answers `201`, so assert `assertSuccessful()`:

```php title="tests/Feature/Projects/CheckProjectNameTest.php"
test('a free project name is available', function () {
    $this->actingAs(User::factory()->create())
        ->postJson(route('projects.check-name'), ['name' => 'Launch'])
        ->assertSuccessful()
        ->assertExactJson(['available' => true, 'suggestion' => null]);
});

test('a taken project name comes with a suggestion', function () {
    Project::query()->create(['name' => 'Launch', 'description' => null]);

    $this->actingAs(User::factory()->create())
        ->postJson(route('projects.check-name'), ['name' => 'Launch'])
        ->assertSuccessful()
        ->assertExactJson(['available' => false, 'suggestion' => 'Launch 2']);
});
```

Done when `composer ci:check` passes.

## When a check fails

- `ci:check:test` fails with `App\Http\Controllers\ProjectController::recent() returns array, which`: the action answers a list (or a collection, a model) that no Data class types. Return one Data class holding it, such as `RecentProjectsData` with a `list<ProjectData>` property (`strictly.jsonResponse`).
- `ci:check:test` fails with `App\Data\ProjectData|null, a nullable or union type, which declares`: the action may answer something other than its Data class. Return the Data class on every path: a missing value goes in a nullable property, a refusal is `abort()` or a validation error (`strictly.jsonResponse`).
- `ci:check:lint` fails with ``Inertia's `useHttp()` is untyped: use `useRouteHttp(route, init)` ``: call the route through `useRouteHttp`.
- `ci:check:tsc` fails with `error TS2339: Property 'response' does not exist on type 'HttpResult<SearchQueryData, SearchResultsData>'.`: check `result.ok` before reading `result.response`.
- `ci:check:test` fails with `The closure route GET stats returns App\Data\StatsData`: move the route to a controller action that returns the Data class.
- `ci:check:test` fails with `The closure route GET projects/recent returns array, which Laravel`: the closure route answers JSON that no Data class types. Move it to a controller action that returns one Data class holding the value, such as `RecentProjectsData` with a `list<ProjectData>` property.
- `ci:check:tsc` fails with `error TS18046: 'result.response' is of type 'unknown'.`: the action declares no Data class return type. Declare one.
- `ci:check:tsc` fails with `error TS2353: Object literal may only specify known properties, and '"users"' does not exist in type '{ documents: number; projects: number; }'.`: a response a test received doesn't match its Data class. Build the response from the class's own fields.
- `ci:check:coverage` fails with `✗ GET /search/stats: no feature test calls this JSON endpoint: add a feature test that calls GET /search/stats and gets a successful App\Data\SearchStatsData response.`: add the step 4 tests.
- `ci:check:coverage` fails with `✗ SearchResultsData.hits is only ever empty (at POST /search › hits): add a feature test that calls POST /search with at least one item in hits.`: call it once with data that fills the list.
