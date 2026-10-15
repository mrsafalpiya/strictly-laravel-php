---
name: strictly-add-form
description: "Adds a form or any request that sends a payload the Strictly Laravel way: an input class the action type-hints, a TypedForm or useRouteForm typed by the route, and feature tests that submit it. Use when adding a form, a field, an input, validation, or a create/update/delete request."
---
# Add a form or input

The worked example adds a form that creates a `Project` (`name`, nullable `description`). The route's action decides the payload type: the form, its field names and its error keys all come from the input class the action type-hints.

## 1. Write the input class

An input class extends `StrictlyLaravel\Data\InputData`. Its types are the rules: non-null is required, `int` is `integer`, an array is `present`, each item of a typed list is validated, a literal union is `in:`. Add attributes for anything else:

```php title="app/Data/StoreProjectData.php"
final class StoreProjectData extends InputData
{
    public function __construct(
        #[Max(80), Unique('projects')]
        public string $name,
        #[Max(500)]
        public ?string $description,
    ) {}
}
```

- A nested object is a property typed by a plain `Data` class; a list is `public array $tasks` with `@param list<TaskInputData> $tasks` on the constructor. A nested class that has `rules()` extends `InputData` too, or its `rules()` replace the ones inferred from its types.
- For an update where a field may be left out, type it `Optional|string` (`Spatie\LaravelData\Optional`); the key is then optional in TypeScript.
- Extra rules go in `public static function rules(): array`; they are merged with the inferred ones.
- A field that holds a model's key declares the model: `#[KeyOf(User::class)] public ?int $assigneeId`, or `#[KeyOf(Label::class)] public array $labelIds` for a list (`StrictlyLaravel\Data\KeyOf`; `#[KeyOf(Issue::class, 'number')]` for a key by another field). TypeScript then takes only that model's key for it, so fill it from the Data the server sent (`member.id`); for a select, look its string value up among the options' keys instead of `Number(value)`. Keep the `exists` rule: it checks the row exists for this user.
- Authorize in the input class, which runs before validation: `public static function authorize(Request $request): bool`.
- `authorize()` and `rules()` receive the route's bound models: name a model parameter after the route's (`authorize(Project $project)` for `{project}`, `rules(Label $label)` to `->ignore($label->id)`). Don't read `$request->route('project')`. Only an input class gets them; a query class's or a plain Data class's model parameter would be a new, empty model.

## 2. Type-hint it in the action

```php title="app/Http/Controllers/ProjectController.php"
    public function store(StoreProjectData $data): RedirectResponse
    {
        Project::query()->create([
            'name' => $data->name,
            'description' => $data->description,
        ]);

        FlashData::toast(ToastType::Success, 'Project created.');

        return back();
    }
```

```php title="routes/web.php"
    Route::post('projects', [ProjectController::class, 'store'])->name('projects.store');
```

Read the payload only through `$data`. Never take a FormRequest (`strictly.formRequest`): its `rules()` and `authorize()` belong in the input class. An action without an input class reads no input from the request (`strictly.inputRead`), since nothing types it. Every validation error must be a field of the input; a refusal that isn't about a field is a toast: `FlashData::toast(ToastType::Error, '…'); return back();`.

## 3. Build the form from the route

`<TypedForm>` takes the route's `.form()`; `field()` names each control and `errors` is keyed by the input's fields (nested ones as `'settings.notify'`, list items as `'tasks.0.title'`):

```tsx title="resources/js/components/projects/create-project-form.tsx"
import { TypedForm } from '@mrsafalpiya/strictly-laravel/react';
import { store } from '@/actions/App/Http/Controllers/ProjectController';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

export function CreateProjectForm() {
    return (
        <TypedForm {...store.form()} resetOnSuccess>
            {({ errors, field, processing }) => (
                <>
                    <Input name={field('name')} placeholder="Name" />
                    <InputError message={errors.name} />
                    <textarea name={field('description')} />
                    <InputError message={errors.description} />
                    <Button disabled={processing}>Create project</Button>
                </>
            )}
        </TypedForm>
    );
}
```

The other request helpers of `@mrsafalpiya/strictly-laravel/react`, all typed by the route:

- `const form = useRouteForm(update(project), defaults)` for a form whose state the component drives (`form.data`, `form.setData`, `form.errors`, `form.submit()`). Send `defaults` from the page as a prop typed by the input class.
- `submit(update(project), { name })` for a one-off request with a payload.
- `visit(destroy(project))` for a request without a payload.

A component that forwards `name` to a control declares it `name?: FieldName` (type `FieldName` from `@mrsafalpiya/strictly-laravel/react`).

## 4. Submit it in feature tests

Submit it valid (each nullable field both `null` and set across the tests) and invalid, or `ci:check:coverage` fails:

```php title="tests/Feature/Projects/CreateProjectTest.php"
test('a project is created from the form', function () {
    $this->actingAs(User::factory()->create())
        ->from(route('dashboard'))
        ->post(route('projects.store'), ['name' => 'Launch', 'description' => 'The first release'])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasNoErrors();

    expect(Project::query()->where('name', 'Launch')->exists())->toBeTrue();
});

test('a project needs no description', function () {
    $this->actingAs(User::factory()->create())
        ->post(route('projects.store'), ['name' => 'Backlog', 'description' => null])
        ->assertSessionHasNoErrors();
});

test('a taken name is an error on the name field', function () {
    Project::query()->create(['name' => 'Launch', 'description' => null]);

    $this->actingAs(User::factory()->create())
        ->from(route('dashboard'))
        ->post(route('projects.store'), ['name' => 'Launch', 'description' => null])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHasErrors(['name']);
});
```

Done when `composer ci:check` passes.

## When a check fails

- `ci:check:lint` fails with ``Inertia's `useForm()` is untyped: use `useRouteForm(route, defaults)` `` (likewise `<Form>`, `useHttp()`, `router.post()`): use the helper it names.
- `ci:check:lint` fails with ``Untyped field name "email": use `name={field('…')}` from `<TypedForm>`'s render prop``: name every control with `field()`.
- `ci:check:tsc` fails with `error TS2322: Type 'string' is not assignable to type 'FieldName'.`: a plain string names a control. Use `field('…')`, and type a forwarded `name` as `FieldName`.
- `ci:check:tsc` fails with `error TS2339: Property 'nmae' does not exist on type 'Partial<Record<"name"`: the error key isn't a field of the input.
- `ci:check:test` fails with `App\Http\Controllers\ProjectController::store() takes`: the action takes a FormRequest. Move its `rules()` and `authorize()` into an input class, take that instead, and read the payload from `$data`.
- `ci:check:test` fails with `App\Http\Controllers\ProjectController::store() reads input`: the action takes no input class, so nothing types what it reads (`$request->input()`, `string()`, `all()`, `validate()`, `$request->name`). Take an input class and read the field from `$data`; a GET's query string goes in a query class.
- `ci:check:test` fails with `doesn't extend StrictlyLaravel\Data\InputData.`: the action's Data parameter must extend `InputData`.
- `ci:check:test` fails with `App\Data\BulkUpdateIssuesData::$ids is validated by an exists rule`: the field holds a model's key; declare the model with `#[KeyOf(Issue::class)]` (`strictly.keyOf`). A value that is no model's key takes `@phpstan-ignore strictly.keyOf (why)`.
- `ci:check:tsc` fails with `error TS2345: Argument of type 'number & { readonly [keyOf]: "Issue.id"; }' is not assignable to parameter of type '(number & { readonly [keyOf]: "User.id"; }) | null'.`: an issue's key was sent where the input takes a user's. Send the key of the model the field declares.
- `ci:check:test` fails with `App\Data\StoreTaskData::$task nests App\Data\TaskData, whose rules()`: a Data class with `rules()` nested in an input must extend `InputData` too.
- `ci:check:test` fails with `Gate::authorize() after App\Data\StoreProjectData was validated, so`: move the check into the input's `authorize()`.
- `ci:check:test` fails with `App\Data\UpdateEmailData::authorize() takes App\Models\User $account, but the route users/{user}/email has no {account} parameter.`: name the model parameter after the route's parameter. If it says the route binds `{user}` to a string, the action must take the model (`User $user`) so the route binds it.
- `ci:check:test` fails with `App\Data\TeamFiltersData::rules() takes App\Models\User $user`: only an input class's `authorize()` and `rules()` receive the route's models (`strictly.routeModelParameter`).
- `ci:check:tsc` fails with `error TS2353: Object literal may only specify known properties, and '"project"' does not exist in type 'Partial<Record<"name" | "description" | "estimate"`: the server returned an error key that isn't an input field. Make it a field, or flash a toast instead.
- `ci:check:tsc` fails with `error TS2322: Type '"notify"' is not assignable to type '"name" | "public"'.`: the action read a request key the input doesn't declare. Add it to the input class and read it from `$data`.
- `ci:check:coverage` fails with `✗ POST /projects: no feature test submits to this form endpoint: add a feature test that sends App\Data\StoreProjectData to POST /projects.`: add the step 4 tests.
