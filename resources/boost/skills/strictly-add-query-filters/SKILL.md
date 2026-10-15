---
name: strictly-add-query-filters
description: "Adds query-string filters to a GET page the Strictly Laravel way: a query class the action type-hints, sent back as a prop, and filter links built with the route's typed query option. Use when adding filters, search, sorting, tabs or any ?key=value parameter to a page."
---
# Add query filters

The worked example adds a search box and a sort order to the `projects/index` page (skill `strictly-add-page`). A query class types the query string on both sides: the action receives it parsed, the route function's `query` option only takes its keys and values, and the page reads it back as a prop.

## 1. Write the query class

A query class extends `StrictlyLaravel\Data\QueryData`. Every property has the default an absent key means: a nullable one defaults to `null`, an array to `[]`:

```php title="app/Data/ProjectFiltersData.php"
final class ProjectFiltersData extends QueryData
{
    public function __construct(
        public ?string $search = null,
        public ProjectSort $sort = ProjectSort::Name,
    ) {}
}
```

```php title="app/Enums/ProjectSort.php"
enum ProjectSort: string
{
    case Name = 'name';
    case Newest = 'newest';
}
```

Parsing is lenient: an invalid value falls back to its default instead of a 422, so an old bookmark still opens the page. There is no `authorize()` for a query class; authorize with the route's middleware or a policy.

## 2. Type-hint it in the GET action and send it back

```php title="app/Http/Controllers/ProjectController.php"
    public function index(ProjectFiltersData $filters): IndexPage
    {
        $search = $filters->search;

        $projects = Project::query()
            ->when($search !== null, fn (Builder $query) => $query->whereLike('name', "%{$search}%"))
            ->orderBy($filters->sort === ProjectSort::Newest ? 'created_at' : 'name', $filters->sort === ProjectSort::Newest ? 'desc' : 'asc')
            ->get();

        return new IndexPage(
            projects: $projects->map(fn (Project $project): ProjectData => ProjectData::fromModel($project))->all(),
            filters: $filters,
        );
    }
```

```php title="app/Pages/Projects/IndexPage.php"
    /**
     * @param  array<int, ProjectData>  $projects
     */
    public function __construct(
        public array $projects,
        public ProjectFiltersData $filters,
    ) {}
```

## 3. Build filter URLs with the route's `query` option

`index({ query })` takes `Partial<ProjectFiltersData>`; enum values in case order come from `enumValues`:

```tsx title="resources/js/pages/projects/index.tsx"
import { Head, Link } from '@inertiajs/react';
import { visit } from '@mrsafalpiya/strictly-laravel/react';
import { enumValues } from '@/generated';
import type { PageProps } from '@/generated';
import { index } from '@/routes/projects';

type Props = PageProps<'projects/index'>;

export default function Index({ projects, filters }: Props) {
    const search = (value: string) => {
        const query = { ...filters, search: value === '' ? null : value };

        visit(index({ query }), { preserveState: true, replace: true });
    };

    return (
        <>
            <Head title="Projects" />
            <input
                type="search"
                defaultValue={filters.search ?? ''}
                onChange={(event) => search(event.target.value)}
            />
            <nav>
                {enumValues.ProjectSort.map((sort) => (
                    <Link
                        key={sort}
                        href={index({ query: { ...filters, sort } })}
                    >
                        {sort}
                    </Link>
                ))}
            </nav>
            <ul>
                {projects.map((project) => (
                    <li key={project.id}>{project.name}</li>
                ))}
            </ul>
        </>
    );
}
```

`mergeQuery` keeps the current URL's other keys: `index({ mergeQuery: { sort } })`. Wayfinder leaves `null` and `[]` out of the URL, which the server reads back as the default.

## 4. Request it with and without filters in feature tests

Send each nullable filter both absent and set, or `ci:check:coverage` reports the `filters` prop's gap:

```php title="tests/Feature/Projects/ProjectIndexTest.php"
beforeEach(function () {
    Project::query()->create(['name' => 'Launch', 'description' => 'The first release']);
    Project::query()->create(['name' => 'Backlog', 'description' => null]);
});

test('the projects page lists every project by name without filters', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['search' => null, 'sort' => 'name'])
            ->where('projects.0.name', 'Backlog'));
});

test('the projects page filters by the query string', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('projects.index', ['search' => 'Lau', 'sort' => 'newest']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters', ['search' => 'Lau', 'sort' => 'newest'])
            ->has('projects', 1));
});
```

Done when `composer ci:check` passes.

## When a check fails

- `ci:check:test` fails with `App\Data\ProjectFiltersData::$sort has no default, so a query string`: give the property the default an absent key means.
- `ci:check:test` fails with `App\Data\ProjectFiltersData::$statuses is a list, so it must default`: default a list to `[]` and a nullable property to `null`.
- `ci:check:tsc` fails with `error TS2561: Object literal may only specify known properties, but 'statuse' does not exist in type 'Partial<ProjectFiltersData>'.`: the key isn't a property of the query class.
- `ci:check:tsc` fails with `error TS2322: Type '"oldest"' is not assignable to type 'ProjectSort | undefined'.`: the value isn't one the property's type allows.
- `ci:check:test` fails with `Expected response status code [422] but received 200.`: an invalid query value falls back to its default; assert the default instead of a 422.
