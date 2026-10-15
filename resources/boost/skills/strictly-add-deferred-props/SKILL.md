---
name: strictly-add-deferred-props
description: "Adds deferred, lazy or infinite-scroll page props the Strictly Laravel way: Lazy|Data props and ScrollProp on the Page class, rendered through forPage()'s Deferred, WhenVisible and InfiniteScroll, and feature tests that load them. Use when a prop should load after the first render, only on request, when scrolled into view, or page by page as the user scrolls."
---
# Add deferred or infinite-scroll props

The worked example gives the `projects/index` page (skill `strictly-add-page`) a `stats` prop that loads right after the first render and turns its project list into an infinite scroll.

## 1. Declare the props on the Page class

- A **deferred** or **lazy** prop is `Lazy|XData`; TypeScript types it optional (`stats?: ProjectStatsData`), because it's missing until it's loaded.
- An **infinite-scroll** prop is a `ScrollProp` whose `@var` names the paginated Data collection; TypeScript types it as the collection Inertia sends (`{ data, links, meta }`).

```php title="app/Pages/Projects/IndexPage.php"
final class IndexPage extends Page
{
    public function __construct(
        /** @var ScrollProp<CursorPaginatedDataCollection<array-key, ProjectData>> */
        public ScrollProp $projects,
        public Lazy|ProjectStatsData $stats,
    ) {}
}
```

```php title="app/Data/ProjectStatsData.php"
final class ProjectStatsData extends Data
{
    public function __construct(
        public int $total,
        public int $withoutDescription,
    ) {}
}
```

## 2. Build them in the action

`Lazy::inertiaDeferred(fn () => …)` loads right after the first render; `Lazy::inertia(fn () => …)` only when the page asks for it in a partial reload. Use `Lazy` only for props that can be missing. A prop that is always sent is a plain property (`public ProjectStatsData $stats`), not `Lazy::closure()` or `->defaultIncluded()`, which are generated as optional. A `ScrollProp` over a laravel-data collection needs the paginator's metadata passed explicitly:

```php title="app/Http/Controllers/ProjectController.php"
    public function index(): IndexPage
    {
        $paginator = Project::query()->orderBy('id')->cursorPaginate(self::PER_PAGE);

        return new IndexPage(
            projects: new ScrollProp(
                ProjectData::collect($paginator, CursorPaginatedDataCollection::class),
                metadata: ScrollMetadata::fromPaginator($paginator),
            ),
            stats: Lazy::inertiaDeferred(fn (): ProjectStatsData => new ProjectStatsData(
                total: Project::query()->count(),
                withoutDescription: Project::query()->whereNull('description')->count(),
            )),
        );
    }
```

Use `Lazy` from `Spatie\LaravelData`, never Inertia's `Inertia::defer()` or `DeferProp`: those have no value type.

## 3. Render them through `forPage()`

`const page = forPage<'projects/index'>()` gives the page's typed `Deferred`, `WhenVisible` and `InfiniteScroll`, whose `data` takes only the page's prop names, and the prop-name helpers `page.only()`, `page.except()` and `page.reset()`. Handle the deferred prop's `undefined`:

```tsx title="resources/js/pages/projects/index.tsx"
import { Head } from '@inertiajs/react';
import { forPage } from '@mrsafalpiya/strictly-laravel/react';
import type { PageProps } from '@/generated';

type Props = PageProps<'projects/index'>;

const page = forPage<'projects/index'>();

export default function Index({ projects, stats }: Props) {
    return (
        <>
            <Head title="Projects" />
            <page.Deferred data="stats" fallback={<p>Counting…</p>}>
                {stats !== undefined && (
                    <p>
                        {stats.total} projects, {stats.withoutDescription}{' '}
                        without a description
                    </p>
                )}
            </page.Deferred>
            <page.InfiniteScroll data="projects" onlyNext>
                {projects.data.map((project) => (
                    <article key={project.id}>
                        <h2>{project.name}</h2>
                        {project.description !== null && (
                            <p>{project.description}</p>
                        )}
                    </article>
                ))}
            </page.InfiniteScroll>
        </>
    );
}
```

Load a `Lazy::inertia()` prop on demand with `router.reload({ only: page.only('stats') })`. When a filter changes the list, start it over: `visit(index({ query }), { reset: page.reset('projects') })`.

## 4. Load them in feature tests

A test must load every deferred prop (`loadDeferredProps()`, or a partial reload with `reload(only: […])`), and the scroll prop's items must show every shape, or `ci:check:coverage` fails. Requesting the next page tests the scroll itself:

```php title="tests/Feature/Projects/ProjectIndexTest.php"
test('the projects page lists projects and loads their stats after the first render', function () {
    Project::query()->create(['name' => 'Launch', 'description' => 'The first release']);
    Project::query()->create(['name' => 'Backlog', 'description' => null]);

    $this->actingAs(User::factory()->create())
        ->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->has('projects.data', 2)
            ->missing('stats')
            ->loadDeferredProps(fn (Assert $reload) => $reload
                ->where('stats.total', 2)
                ->where('stats.withoutDescription', 1)));
});

test('scrolling to the end of the list loads the next page of projects', function () {
    foreach (range(1, ProjectController::PER_PAGE + 1) as $number) {
        Project::query()->create(['name' => "Project {$number}", 'description' => null]);
    }

    $user = User::factory()->create();
    $first = $this->actingAs($user)->get(route('projects.index'));
    $cursor = data_get($first->viewData('page'), 'scrollProps.projects.nextPage');
    $version = data_get($first->viewData('page'), 'version');

    expect($cursor)->toBeString();

    // What <page.InfiniteScroll data="projects"> requests when the user reaches the end of the list.
    $this->actingAs($user)
        ->get(route('projects.index', ['cursor' => $cursor]), [
            Header::INERTIA => 'true',
            Header::VERSION => is_string($version) ? $version : '',
            Header::PARTIAL_COMPONENT => 'projects/index',
            Header::PARTIAL_ONLY => 'projects',
            Header::INFINITE_SCROLL_MERGE_INTENT => 'append',
        ])
        ->assertOk()
        ->assertJsonCount(1, 'props.projects.data');
});
```

Done when `composer ci:check` passes.

## When a check fails

- `ci:check:generate` fails with ``ReportsPage.stats is typed with Inertia\DeferProp, which is absent until it is loaded and has no value type: declare it `Lazy|YourData` and pass `Lazy::inertiaDeferred(fn () => …)` ``: use laravel-data's `Lazy`.
- `ci:check:lint` fails with ``strictly(typed-inertia-components): Use `page.Deferred` from `const page = forPage<'component'>()` ``: render `page.Deferred`, `page.WhenVisible` and `page.InfiniteScroll`, not Inertia's own.
- `ci:check:lint` fails with ``strictly(typed-prop-names): `only` must be `page.only(…)` with `const page = forPage<'component'>()` ``: build `only`, `except` and `reset` lists with `page.only()`, `page.except()` and `page.reset()`.
- `ci:check:tsc` fails with `resources/js/pages/reports.tsx(9,18): error TS18048: 'stats' is possibly 'undefined'.`: render the deferred prop only once it's loaded.
- `ci:check:tsc` fails with `error TS2322: Type '"statistics"' is not assignable to type 'PropName<"reports"> | PropName<"reports">[]'.`: `data` names a prop the Page class doesn't have.
- `ci:check:coverage` fails with ``✗ reports › stats: no feature test loads this deferred prop: add a feature test that renders reports and loads stats (`$page->loadDeferredProps()` in `assertInertia()`, or a partial reload).``: add the first step 4 test.
- `ci:check:coverage` fails with `✗ EntryData.note is only ever null (at feed › entries[].note): add a feature test that renders feed with entries[].note not null.`: the scroll prop's items need every shape too.
