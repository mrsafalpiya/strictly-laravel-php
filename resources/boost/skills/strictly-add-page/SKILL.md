---
name: strictly-add-page
description: "Adds an Inertia page the Strictly Laravel way: a Page class built from Data classes, a controller action returning it, a component typed by PageProps, and a feature test. Use when creating a page or screen, or adding or changing a page's props."
---
# Add a page

The worked example adds `projects/index`, listing the projects of a `Project` model (`name`, nullable `description`).

## 1. Create the Page class and its component

```bash
php artisan make:page projects/index
```

This writes `app/Pages/Projects/IndexPage.php` and `resources/js/pages/projects/index.tsx`, already in sync, and regenerates the types. The page component's path is the Page class's name: `App\Pages\Projects\IndexPage` renders `resources/js/pages/projects/index.tsx`.

## 2. Describe each value as a Data class

A Data class is `final`, lives in `App\Data`, and is built from its model by a named constructor, so only these fields reach the browser:

```php title="app/Data/ProjectData.php"
final class ProjectData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $description,
    ) {}

    public static function fromModel(Project $project): self
    {
        return new self(
            id: $project->id,
            name: $project->name,
            description: $project->description,
        );
    }
}
```

## 3. Declare the props on the Page class

The constructor's promoted properties are the page's props. Declare a list as a `list<…>` of its item type, never `array<int, …>`, which PHP sends as a JSON object once a `filter()` leaves a gap in its keys:

```php title="app/Pages/Projects/IndexPage.php"
final class IndexPage extends Page
{
    /**
     * @param  list<ProjectData>  $projects
     */
    public function __construct(
        public array $projects,
    ) {}
}
```

Prop types: a Data class, a scalar, a backed enum, `?X` for a value that may be missing, `Lazy|XData` for a deferred prop (skill `strictly-add-deferred-props`).

## 4. Return the Page class from a controller action

```php title="app/Http/Controllers/ProjectController.php"
    public function index(): IndexPage
    {
        return new IndexPage(
            projects: Project::query()
                ->orderBy('name')
                ->get()
                ->map(fn (Project $project): ProjectData => ProjectData::fromModel($project))
                ->all(),
        );
    }
```

```php title="routes/web.php"
    Route::get('projects', [ProjectController::class, 'index'])->name('projects.index');
```

## 5. Type the component by the page's props

```tsx title="resources/js/pages/projects/index.tsx"
import { Head } from '@inertiajs/react';
import type { PageProps } from '@/generated';

type Props = PageProps<'projects/index'>;

export default function Index({ projects }: Props) {
    return (
        <>
            <Head title="Projects" />
            <ul>
                {projects.map((project) => (
                    <li key={project.id}>
                        <h2>{project.name}</h2>
                        {project.description !== null && (
                            <p>{project.description}</p>
                        )}
                    </li>
                ))}
            </ul>
        </>
    );
}
```

Link to it with `index()` from `@/routes/projects`. Shared props (`auth`, `name`, …) are typed on `usePage().props`; call `usePage()` without a type argument.

## 6. Render every shape in a feature test

The test renders the page with each nullable prop both `null` and set, and each list non-empty, or `ci:check:coverage` fails:

```php title="tests/Feature/Projects/ProjectIndexTest.php"
test('the projects page lists every project, with or without a description', function () {
    Project::query()->create(['name' => 'Launch', 'description' => 'The first release']);
    Project::query()->create(['name' => 'Backlog', 'description' => null]);

    $this->actingAs(User::factory()->create())
        ->get(route('projects.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('projects/index')
            ->has('projects', 2)
            ->where('projects.0.name', 'Backlog')
            ->where('projects.1.description', 'The first release'));
});
```

Done when `composer ci:check` passes.

## When a check fails

- `ci:check:test` fails with `App\Pages\Labels\IndexPage::$labels is typed array<int, LabelData>,`: declare it `list<LabelData>`. A query's `->get()->map(…)->all()` is a list; after `filter()`, `unique()` or `sortBy()`, end with `->values()->all()`.
- `ci:check:test` fails with `Inertia::render() renders a page whose props have no generated type.`: return the Page class instead (`Route::inertia()` and `inertia('…')` read the same).
- `ci:check:tsc` fails with `error TS2344: Type '"projects/index"' does not satisfy the constraint 'keyof InertiaPages'.`: a file under `resources/js/pages` has no Page class. Add one, or run `php artisan make:page`.
- `ci:check:tsc` fails with `error TS2344: Type '{ unsentProps: "owner"; }' does not satisfy the constraint '(props: ProjectsShowPage) => unknown'.`: the component reads a prop the Page class doesn't send. Add it to the Page class.
- `ci:check:tsc` fails with `error TS2339: Property 'title' does not exist on type 'ProjectsShowPage'.`: the prop was renamed or removed in PHP. Follow the PHP name.
- `ci:check:coverage` fails with `✗ projects: no feature test renders this page: add a feature test that requests a route returning App\Pages\ProjectsPage.`: add the step 6 test.
- `ci:check:coverage` fails with `✗ MembersPage.members is only ever empty (at members › members): add a feature test that renders members with at least one item in members.`: create the records first (skill `strictly-close-coverage-gap`).
- `ci:check:test` fails with `Data class App\Data\ProjectData must be final.`: make it `final`.
