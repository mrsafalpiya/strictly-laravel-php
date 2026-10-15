---
name: strictly-close-coverage-gap
description: "Closes a Contract Coverage gap the Strictly Laravel way: reads the ✗ line strictly:coverage prints and adds the feature test it names, so the server really sends that shape. Use when composer ci:check:coverage (or ci:check) fails with a ✗ gap, or a new page, prop, endpoint or event needs test coverage."
---
# Close a Contract Coverage gap

Feature tests record what the server really sends as Contracts, and `tsc` checks each one against its generated type. Contract Coverage (`composer ci:check:coverage`, `php artisan strictly:coverage --strict`) fails on whatever no Contract showed: a page, deferred prop, form endpoint, JSON endpoint or broadcast event no test exercised, and a shape the type allows that no test sent. Until a test sends it, `tsc` has never checked it. Close a gap by adding a test that makes the server send it; never by changing the type to fit the tests.

## 1. Read the gap

Each gap is one line: what was never seen, where (`at <page or endpoint> › <path>`), and the test to add. The worked example's page lists projects, and its only test creates a project without a description:

```text
✗ ProjectData.description is only ever null (at projects/index › projects[].description): add a feature test that renders projects/index with projects[].description not null.
```

Run `composer ci:check:test` and then `composer ci:check:coverage` to see the current gaps.

## 2. Add the test the gap names

Set up the data that makes the server send the missing shape, and request what the gap names (`renders projects/index`). The request itself records the Contract; assert what the test is about:

```php title="tests/Feature/Projects/ProjectIndexTest.php"
test('the projects page shows a project description', function () {
    Project::query()->create(['name' => 'Launch', 'description' => 'The first release']);

    $this->actingAs(User::factory()->create())
        ->get(route('projects.index'))
        ->assertInertia(fn (Assert $page) => $page->where('projects.0.description', 'The first release'));
});
```

Done when `composer ci:check` passes.

## What each gap needs

| The gap says | Add a test that |
|---|---|
| `no feature test renders this page` | requests a route returning that Page class. |
| `no feature test loads this deferred prop` | renders the page and loads it: `$page->loadDeferredProps(…)` in `assertInertia()`, or `reload(only: [...])`. |
| `no feature test submits to this form endpoint` | sends its input class's fields to the route. |
| `no feature test calls this JSON endpoint` | calls the route and gets a successful response. |
| `no feature test broadcasts this event` | makes the event broadcast, without `Event::fake()` (spy with `Event::listen()`). |
| `is only ever null` | sends the value set. |
| `is only ever empty` | sends the list with at least one item. |
| `is only ever absent` | sends the optional prop or flash key. |
| `is never seen as PriorityChangedData` | sends that union variant. |
| `is never seen as int` | sends that member of the scalar union. |
| `is declared for a vendor action that never uses it` | submits that field to the vendor route, or remove it from `config/strictly.php`. |

A shape no request can produce is a type that says too much: make the property non-nullable, or the list's minimum explicit, in PHP. Shapes are proven per property, so one test may cover several gaps.

Contract Coverage sees only the shapes laravel-data describes: one item type per list or map, and nothing inside a PHPDoc array shape. So PHPStan (`strictly.dataShape`) rejects a Data property that holds an array shape (`list<array{lead: ?string}>`), a list or map of a union (`list<int|string>`, `list<IssueData|MilestoneData>`) or a list or map of lists. Model the item as a Data class (`list<ProjectSummaryData>`, with `public ?string $lead`; for a union of Data classes, a Data class whose one property is the union, its variants told apart by a literal discriminant); its properties' gaps then show up here like any other.

## When a check fails

- `ci:check:coverage` fails with `✗ ProjectData.description is only ever null (at projects/index › projects[].description): add a feature test that renders projects/index with projects[].description not null.`: the step 2 test.
- `ci:check:coverage` fails with `✗ projects: no feature test renders this page: add a feature test that requests a route returning App\Pages\ProjectsPage.`: request a route that returns the Page class.
- `ci:check:coverage` fails with `✗ FlashData.highlight is only ever absent (at flash data › highlight): add a feature test that flashes data with highlight sent.`: make a request whose response flashes it.
- `ci:check:coverage` fails with `✗ ChangePage.change is never seen as PriorityChangedData (at change › change): add a feature test that renders change with change as PriorityChangedData.`: render that variant.
- `ci:check:coverage` fails with `Contract Coverage: no Contracts are recorded. Run the feature tests first (composer ci:check:test), then this command.`: run `composer ci:check:test` first, or the whole `composer ci:check`.
- `ci:check:test` fails with `App\Pages\Projects\IndexPage::$projects has an array shape`: model the item as a Data class, `list<ProjectSummaryData>` with `public ?string $lead` on it, so Contract Coverage checks its fields.
- `ci:check:test` fails with `Attempted to lazy load [passkeys] on model [App\Models\User] but lazy loading is disabled.`: the new test reached code that lazy loads; eager load the relation (`with()`, `load()`) in the code under test.
