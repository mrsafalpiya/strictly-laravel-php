# Strictly Laravel

This app runs Strictly Laravel: end-to-end type safety between Laravel and React. PHP is the Source of Truth for every value that crosses a Type Boundary (routes, page props, shared props, form payloads, validation errors, enums, deferred props, flash, JSON endpoints, broadcasts, translations, exposed config). You write the PHP; the TypeScript is generated from it. `composer ci:check` proves the generated types against what the server actually sends in the feature tests (Contracts) and fails until every shape those types allow has been sent (Contract Coverage).

These rules take precedence over Boost's generic Inertia, Wayfinder and Laravel guidance. Where that guidance shows `Inertia::render()`, `useForm()`, `<Form>`, `useHttp()`, `router.post()`, `<Link data>`, `<Deferred>` or a hard-coded URL, use the Strictly Laravel way below: `ci:check` rejects the untyped one.

## Skills

Activate the matching skill before you start; each one is a worked example that passes `ci:check`:

- `strictly-add-page`: a new Inertia page, or new props on one.
- `strictly-add-form`: a form, an input class, or any request that sends a payload.
- `strictly-add-query-filters`: filters, search or sorting in a GET page's query string.
- `strictly-add-json-endpoint`: a route that answers JSON instead of a page.
- `strictly-add-deferred-props`: a deferred, lazy or infinite-scroll prop.
- `strictly-add-broadcast-event`: a broadcast event, or listening for one in React.
- `strictly-add-translation`: any user-facing text.
- `strictly-close-coverage-gap`: `ci:check:coverage` reports a `✗` gap.

## PHP

- Everything React receives is a **Data class** (spatie/laravel-data): `final`, in the `App\Data` namespace, which holds only Data classes. Build it from a model with a named constructor, `ProjectData::fromModel($project)`. Send Data, never models, arrays or JsonResources.
- An array property names its item type: `@param array<int, ProjectData> $projects` (or `@var`). Dates are `CarbonInterface` (an ISO string in TypeScript).
- A page is rendered by returning its **Page class** (`App\Pages\Projects\IndexPage` is the page `projects/index`) from a controller action. `php artisan make:page projects/index` creates the class and its component.
- Props every page gets are properties of `App\Data\SharedProps`. Flash with `FlashData::toast(ToastType::Success, '…')`; add a property to `App\Data\FlashData` to flash something new.
- A request's payload is an **input class**: the action type-hints a Data class extending `StrictlyLaravel\Data\InputData`, which validates the request with rules inferred from its types. Authorize in the input's `public static function authorize(...): bool`.
- A GET's query string is a **query class** extending `StrictlyLaravel\Data\QueryData`, every property with a default. A route whose action takes none takes no query string, and a controller reads keys only from its query class, never with `$request->query()`.
- A **JSON endpoint** is an action whose declared return type is a Data class (not a Page class).
- A **broadcast event** implements `ShouldBroadcast`, uses `StrictlyLaravel\Broadcasting\BroadcastsData` and declares `broadcastData(): SomeData`.
- A `BelongsTo` relation whose foreign key is `NOT NULL` is declared non-nullable on the model, `/** @property-read Project $project */`, so PHPStan doesn't type it `Project|null`. Don't add `?->` or nullable Data properties for it.
- A route parameter bound to a model type-hints the model (`Project $project`); its key is typed `RouteKey<'Project', number>` in TypeScript.
- A setting or feature flag React needs is a property of `SharedProps`, read with `config()->boolean()`, `config()->string()` and friends. A build-time value is a `VITE_*` variable listed in `.env.example`.
- Backed enums anywhere in `app/` become string or number unions; their values in case order are `enumValues.ProjectStatus` from `@/generated`.

## React

- Types for server data come from `@/generated`: `import type { PageProps, ProjectData } from '@/generated'`. A page component takes `PageProps<'projects/index'>`.
- URLs come from Wayfinder: `import { index } from '@/routes/projects'` or `import { store } from '@/actions/App/Http/Controllers/ProjectController'`, passing Data objects or their keys as parameters.
- Requests go through the route-typed helpers of `@mrsafalpiya/strictly-laravel/react`: `<TypedForm {...store.form()}>` with `name={field('name')}`, `useRouteForm(route, defaults)`, `submit(route, data)`, `visit(route)` and `useRouteHttp(route, init)`.
- Prop names go through `const page = forPage<'projects/index'>()`: `page.only('stats')`, `page.reset('projects')`, `<page.Deferred>`, `<page.WhenVisible>`, `<page.InfiniteScroll>`, `page.optimistic(…)`. Call `usePage()` without a type argument.
- Broadcasts: `useChannelEvent(channel('projects.{project}', { project }), 'ProjectUpdated', cb)` from `@/generated/broadcasting`. Text: `t('projects.index.title')` from `@/generated/translations`.
- Narrow with checks (`!== null`, `!== undefined`, `switch` on a discriminant) instead of `as` or `!`.
- Every suppression names its rule and gives a reason: `// oxlint-disable-next-line strictly/typed-requests -- why`, `// @ts-expect-error why`, `@phpstan-ignore strictly.inertiaRender (why)`.

## Tests

Every page, deferred prop, form endpoint, JSON endpoint and broadcast event needs a feature test that exercises it, and between them the tests must send every shape its type permits: each nullable value both `null` and set, each list non-empty, each union variant, each optional prop sent. Feature tests record Contracts on their own; nothing to call. Ids in tests are not `1` (each table has its own id range): assert against the model's own `id`.

## What checks what

`composer ci:check` runs these steps in order; run one alone with `composer ci:check:<step>`.

| Step | Tool | Catches |
|---|---|---|
| `install` | `strictly:install --check` | A Strictly Laravel hook the app lost or hasn't picked up. Fix: `php artisan strictly:install`. |
| `generate` | `strictly:generate` | PHP that can't be typed: a property of a class with no TypeScript type, a raw Inertia `DeferProp`, a `{project:slug}` route whose action doesn't type-hint the model. |
| `lint` | oxlint with the `strictly/*` rules, oxfmt | Inertia's untyped APIs, raw prop-name lists, hard-coded URLs, hand-typed `usePage<T>()`, Echo's `useEcho()`, suppressions without a reason, formatting. |
| `test` | Pint, PHPStan max with the `strictly.*` rules, Pest | `Inertia::render()`, `Inertia::flash()`, a non-final Data class, an action input that isn't an `InputData`, authorizing after validation, a query property without a default, a query read from the request, a broadcast without a Data payload, untyped PHP; plus behaviour and locale parity. |
| `coverage` | `strictly:coverage --strict` | Contract Coverage: a page, deferred prop, form endpoint, JSON endpoint or broadcast event no test exercises, and a shape no test sent. |
| `tsc` | `tsc --noEmit` | React using a prop, field, route, query key, error key or translation that doesn't exist; and every Contract the tests recorded checked against its generated type. |

The generated TypeScript (`resources/js/generated`) and the recorded Contracts (`resources/js/__contracts__`) are gitignored: never edit them. `composer dev` regenerates them when you save a file they come from (a Data class, a page, a route, a lang file); `php artisan strictly:generate` does it by hand.
