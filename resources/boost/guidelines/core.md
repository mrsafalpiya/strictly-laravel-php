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
- A request's payload is an **input class**: the action type-hints a Data class extending `StrictlyLaravel\Data\InputData`, which validates the request with rules inferred from its types. Authorize in the input's `public static function authorize(...): bool`. Its `authorize()` and `rules()` receive the route's bound models by parameter name (`authorize(Project $project)`), so never read `$request->route()` there.
- A GET's query string is a **query class** extending `StrictlyLaravel\Data\QueryData`, every property with a default. A route whose action takes none takes no query string, and a controller reads keys only from its query class, never with `$request->query()`.
- A **JSON endpoint** is an action whose declared return type is a Data class (not a Page class).
- A **broadcast event** implements `ShouldBroadcast`, uses `StrictlyLaravel\Broadcasting\BroadcastsData`, declares `broadcastData(): SomeData`, and names each channel pattern it is sent on with `#[BroadcastsOn('projects.{project}')]`.
- A **presence channel**'s callback in `routes/channels.php` returns what each member shares as a Data class declared as its return type (`fn (User $user, Project $project): ?ViewerData => …`), never an array.
- A `BelongsTo` relation whose foreign key is `NOT NULL` is declared non-nullable on the model, `/** @property-read Project $project */`, so PHPStan doesn't type it `Project|null`. Don't add `?->` or nullable Data properties for it.
- A route parameter bound to a model type-hints the model (`Project $project`); it takes the model's key by the field the route binds it by, `Key<'Project', 'id', number>` in TypeScript (`RouteKey<'Project', number>` names it too).
- A Data property that holds a model's key declares the model, in input, query and output classes alike: `#[KeyOf(User::class)] public ?int $assigneeId` (`StrictlyLaravel\Data\KeyOf`; `#[KeyOf(Issue::class, 'number')]` for another field than the primary key). It is then `Key<'User', 'id', number>` in TypeScript, so it takes a member's `id` but not an issue's. A Data class built from a model brands its own `id` by itself. A property named `…Id`, `…Ids`, `…_id` or `…_ids`, or validated by an `exists` rule, must declare it (`strictly.keyOf`).
- A setting or feature flag React needs is a property of `SharedProps`, read with `config()->boolean()`, `config()->string()` and friends. A build-time value is a `VITE_*` variable listed in `.env.example`.
- Backed enums anywhere in `app/` become string or number unions; their values in case order are `enumValues.ProjectStatus` from `@/generated`.

## React

- Types for server data come from `@/generated`: `import type { PageProps, ProjectData } from '@/generated'`. A page component takes `PageProps<'projects/index'>`.
- URLs come from Wayfinder: `import { index } from '@/routes/projects'` or `import { store } from '@/actions/App/Http/Controllers/ProjectController'`, passing Data objects or their keys as parameters.
- Requests go through the route-typed helpers of `@mrsafalpiya/strictly-laravel/react`: `<TypedForm {...store.form()}>` with `name={field('name')}`, `useRouteForm(route, defaults)`, `submit(route, data)`, `visit(route)` and `useRouteHttp(route, init)`.
- Prop names go through `const page = forPage<'projects/index'>()`: `page.only('stats')`, `page.reset('projects')`, `<page.Deferred>`, `<page.WhenVisible>`, `<page.InfiniteScroll>`, `page.optimistic(…)`. Call `usePage()` without a type argument.
- Broadcasts: `useChannelEvent(channel('projects.{project}', { project }), 'ProjectUpdated', cb)` from `@/generated/broadcasting`, and a presence channel's members with `usePresence(channel('projects.{project}.viewers', { project }), { here, joining, leaving })`. Text: `t('projects.index.title')` from `@/generated/translations`, and `choice('projects.index.count', count)` for a pluralised (`|`) message. In PHP, `__('projects.flash.saved', ['key' => $key])` and `trans_choice(…)` are checked the same way.
- Model keys come from the Data the server sent (`member.id`, `issue.id`), never from `Number(value)`: look a select's value up among its options' keys.
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
| `lint` | oxlint with the `strictly/*` rules, oxfmt | Inertia's untyped APIs, raw prop-name lists, hard-coded URLs, hand-typed `usePage<T>()`, Echo's `useEcho()`, `useEchoNotification()`, `useEchoModel()` and `usePresenceChannel()`, suppressions without a reason, formatting. |
| `test` | Pint, PHPStan max with the `strictly.*` rules, Pest | `Inertia::render()`, `Inertia::flash()`, a non-final Data class, an action input, or a nested Data class with `rules()` in one, that isn't an `InputData`, authorizing after validation, a model parameter on a non-input's `authorize()` or `rules()`, a query property without a default, a query read from the request, a model key without `#[KeyOf]`, a broadcast without a Data payload or without `#[BroadcastsOn]`, a presence channel whose callback doesn't declare a Data class, a `__()` or `trans_choice()` key or param the default locale's lang files don't define, untyped PHP; plus behaviour (an input's model parameter the route doesn't bind fails its request) and locale parity. |
| `coverage` | `strictly:coverage --strict` | Contract Coverage: a page, deferred prop, form endpoint, JSON endpoint or broadcast event no test exercises (or a channel its `#[BroadcastsOn]` declares that no test broadcasts it on), and a shape no test sent. |
| `tsc` | `tsc --noEmit` | React using a prop, field, route, query key, error key or translation that doesn't exist, or one model's key where another's is expected; and every Contract the tests recorded checked against its generated type. |

The generated TypeScript (`resources/js/generated`) and the recorded Contracts (`resources/js/__contracts__`) are gitignored: never edit them. `composer dev` regenerates them when you save a file they come from (a Data class, a page, a route, a lang file); `php artisan strictly:generate` does it by hand.
