---
name: strictly-add-broadcast-event
description: "Adds a broadcast event the Strictly Laravel way: an event whose payload is a Data class and whose #[BroadcastsOn] names its channels, a channel defined in routes/channels.php, a typed useChannelEvent listener in React, and a feature test that broadcasts it; and a presence channel whose members are a Data class, followed with usePresence. Use when adding real-time updates, a ShouldBroadcast event, a private, presence or public channel, showing who is online or viewing something, or listening for an event with Echo."
---
# Add a broadcast event

The worked example broadcasts `ProjectUpdated` to everyone viewing a project when its owner renames it, and updates the title live; a presence channel shows who is viewing the project.

## 0. Once per app: install broadcasting

If `routes/channels.php` doesn't exist, run Laravel's installer, then install Echo's React bindings:

```bash
php artisan install:broadcasting --reverb
pnpm add -D laravel-echo pusher-js @laravel/echo-react
```

List every `VITE_REVERB_*` variable it adds in `.env.example` (a `VITE_*` variable missing there fails `tsc`, and one unset in a build's environment fails the build). Replace the stub's untyped `App.Models.User.{id}` channel with typed ones like step 1's.

## 1. Define the channel with a typed callback

A parameter named like the pattern's and type-hinted with a model binds it; return `bool` for a private channel. A presence channel's callback returns what each member shares with the others: a Data class, declared as the callback's return type (`null` refuses the user), never an array:

```php title="routes/channels.php"
Broadcast::channel('projects.{project}', fn (User $user, Project $project): bool => $project->user_id === $user->id);

Broadcast::channel('projects.{project}.viewers', fn (User $user, Project $project): ?ViewerData => $project->user_id === $user->id ? ViewerData::fromModel($user) : null);
```

```php title="app/Data/ViewerData.php"
final class ViewerData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(id: $user->id, name: $user->name);
    }
}
```

A channel class's `join()` declares its return type the same way. `make:channel` writes `array|bool`, which fits either kind: change it to `bool` for a private channel, or the Data class for a presence channel.

A public channel (`new Channel('status')`, which guests can listen on too) has no definition in `routes/channels.php`. Declare it in `config/strictly.php`'s `public_channels` instead, by its name, or by its pattern keyed by itself with the models its parameters hold the route key of:

```php
'public_channels' => [
    'status',
    'projects.{project}.public' => ['project' => Project::class],
],
```

## 2. Write the event with a Data class payload and its channels

The payload is a Data class (here `ProjectData`, built with `fromModel()`) that is the same for every listener. The event implements `ShouldBroadcast`, uses `BroadcastsData`, and declares the payload class as `broadcastData()`'s return type. `broadcastOn()` builds each channel's name at runtime, so name each channel's pattern in a `#[BroadcastsOn]` (`StrictlyLaravel\Broadcasting\BroadcastsOn`), once per pattern; it must be one `routes/channels.php` defines or `config/strictly.php` declares public:

```php title="app/Events/ProjectUpdated.php"
#[BroadcastsOn('projects.{project}')]
final class ProjectUpdated implements ShouldBroadcast
{
    use BroadcastsData, Dispatchable, InteractsWithSockets;

    public function __construct(private readonly Project $project) {}

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("projects.{$this->project->id}");
    }

    public function broadcastData(): ProjectData
    {
        return ProjectData::fromModel($this->project);
    }
}
```

Keep the constructor's properties `private`, and leave `broadcastWith()` to the trait. A `broadcastAs()` returns a fixed string.

## 3. Broadcast it where the change happens

```php title="app/Http/Controllers/ProjectController.php"
        $project->update(['name' => $data->name, 'description' => $data->description]);

        broadcast(new ProjectUpdated($project))->toOthers();
```

## 4. Listen with `useChannelEvent`

`channel()` takes only the patterns `routes/channels.php` defines, with their parameters (a Data object or its route key); the event name is the one Echo listens for (`ProjectUpdated` for `App\Events\ProjectUpdated`, `Projects.Renamed` for `App\Events\Projects\Renamed`), and only an event whose `#[BroadcastsOn]` declares the channel's pattern; the callback receives the payload's type:

```tsx title="resources/js/components/projects/project-title.tsx"
import { useState } from 'react';
import type { ProjectData } from '@/generated';
import { channel, useChannelEvent } from '@/generated/broadcasting';

export function ProjectTitle({ project }: { project: ProjectData }) {
    const [current, setCurrent] = useState(project);

    useChannelEvent(
        channel('projects.{project}', { project }),
        'ProjectUpdated',
        (updated) => setCurrent(updated),
    );

    return (
        <header>
            <h1>{current.name}</h1>
            {current.description !== null && <p>{current.description}</p>}
        </header>
    );
}
```

A public channel is `publicChannel('status')`, or `publicChannel('projects.{project}.public', { project })` with its parameters filled as `channel()` fills them; it takes only a channel `config/strictly.php` declares.

Follow a presence channel's members with `usePresence(channel, { here, joining, leaving })`: each callback is optional and receives the Data class the channel's callback returns. Don't use Echo's `usePresenceChannel()` or `useChannel(name, 'presence')`, whose members are typed by hand:

```tsx title="resources/js/components/projects/project-viewers.tsx"
import { useState } from 'react';
import type { ProjectData, ViewerData } from '@/generated';
import { channel, usePresence } from '@/generated/broadcasting';

export function ProjectViewers({ project }: { project: ProjectData }) {
    const [viewers, setViewers] = useState<ViewerData[]>([]);

    usePresence(channel('projects.{project}.viewers', { project }), {
        here: (members) => setViewers(members),
        joining: (viewer) => setViewers((current) => [...current, viewer]),
        leaving: (viewer) =>
            setViewers((current) =>
                current.filter(({ id }) => id !== viewer.id),
            ),
    });

    return <p>{viewers.map(({ name }) => name).join(', ')}</p>;
}
```

A broadcast notification (`toBroadcast()`) or a model broadcast (`BroadcastsEvents`) has no generated payload type, so don't read it with Echo's `useEchoNotification()` or `useEchoModel()`. Broadcast an app event like `ProjectUpdated` alongside the notification or model change, and listen for that with `useChannelEvent()`.

Don't listen on the channel objects Echo hands out either: `useChannel(…).channel()?.listen(…)`, `echo().private(…).listen(…)` and their `notification()`, `here()`, `joining()` and `leaving()` take any event name and hand-typed data, as `useEcho()` does. Echo's channels are still fine for whispers, `stopListening()` and leaving. A whisper goes from client to client without reaching the server, so nothing generates its type: `whisper(name, data)` and `listenForWhisper(name, callback)` take any name and any payload, and a misspelt name or a payload that disagrees with what's sent passes `tsc` and lint. Keep each whisper's name and payload type in one shared TypeScript module and import it on both sides.

## 5. Broadcast every payload shape in a feature test

Every event a feature test broadcasts is recorded as a Contract (its payload, and its channels against `routes/channels.php`, the public channels `config/strictly.php` declares and the event's `#[BroadcastsOn]`). A test must broadcast each event on each channel it declares, with each nullable payload field both `null` and set, or `ci:check:coverage` fails. Spy with `Event::listen()`; `Event::fake()` stops the broadcast, so nothing is recorded:

```php title="tests/Feature/Projects/UpdateProjectTest.php"
test('updating a project broadcasts it to the project channel', function () {
    $owner = User::factory()->create();
    $project = Project::query()->create(['user_id' => $owner->id, 'name' => 'Launch', 'description' => null]);
    $broadcast = collect();
    Event::listen(ProjectUpdated::class, fn (ProjectUpdated $event) => $broadcast->push($event));

    $this->actingAs($owner)
        ->patch(route('projects.update', $project), ['name' => 'Launch', 'description' => 'The first release'])
        ->assertSessionHasNoErrors();

    $this->actingAs($owner)
        ->patch(route('projects.update', $project), ['name' => 'Launch v2', 'description' => null])
        ->assertSessionHasNoErrors();

    expect($broadcast)->toHaveCount(2);
});
```

A presence channel's member data isn't recorded as a Contract: the test broadcaster never runs a channel's callback.

Done when `composer ci:check` passes. `php artisan reverb:start` serves the events in development.

## When a check fails

- `ci:check:test` fails with `Broadcast event App\Events\ProjectUpdated sends a payload that has no`: return a Data class from `broadcastData()` instead of public properties or `broadcastWith()`.
- `ci:check:test` fails with `Broadcast event App\Events\ProjectUpdated declares no channel it is`: add a `#[BroadcastsOn('projects.{project}')]` for each channel pattern `broadcastOn()` sends it to.
- `ci:check:test` fails with `Presence channel projects.{project}.viewers returns its members' data`: return a Data class from the channel's callback and declare it as the return type.
- `ci:check:test` fails with `Channel projects.{project} returns an array or a bool`: `make:channel`'s `array|bool` return type fits a private channel and a presence channel alike. Declare `bool` for a private channel, or a Data class for a presence channel.
- `ci:check:lint` fails with ``Echo's `useEcho()` takes any event name and a hand-typed payload``: listen with `useChannelEvent()` from `@/generated/broadcasting`.
- `ci:check:lint` fails with ``Echo's `useEchoNotification()` reads a broadcast notification's hand-typed payload``: broadcast an app event with a `BroadcastsData` payload alongside the notification and listen for it with `useChannelEvent()`; the same for `useEchoModel()` and a model change.
- `ci:check:lint` fails with ``Echo's channel `listen()` takes any event name and a hand-typed payload``: listen with `useChannelEvent()` from `@/generated/broadcasting` instead of on the channel object; for `here()`, `joining()` and `leaving()`, follow the members with `usePresence()`.
- `ci:check:lint` fails with ``Echo's `usePresenceChannel()` hands out a presence channel whose members are typed by hand``: follow the members with `usePresence()` from `@/generated/broadcasting`.
- `ci:check:lint` fails with ``Echo's `useChannel(name, 'presence')` hands out a presence channel whose members are typed by hand``: follow the members with `usePresence()` from `@/generated/broadcasting`.
- `ci:check:tsc` fails with `error TS2345: Argument of type '"project.{project}"' is not assignable to parameter of type '"projects.{project}"'.`: the pattern isn't defined in `routes/channels.php`.
- `ci:check:tsc` fails with `error TS2345: Argument of type '"ProjectUpdatd"' is not assignable to parameter of type '"ProjectUpdated"'.`: use the event's Echo name.
- `ci:check:tsc` fails with `error TS2339: Property 'title' does not exist on type 'ProjectData'.`: the callback reads a field the payload class doesn't have.
- `ci:check:tsc` fails with `error TS2339: Property 'email' does not exist on type 'ViewerData'.`: a `usePresence()` callback reads a field the presence channel's Data class doesn't have.
- `ci:check:tsc` fails with `error TS2345: Argument of type '"ProjectUpdated"' is not assignable to parameter of type '"ProjectShared"'.`: the event isn't sent on that channel (its `#[BroadcastsOn]` doesn't declare the pattern), so the callback would never run. Listen on a channel it is sent on, or declare the channel if `broadcastOn()` sends it there.
- `ci:check:tsc` fails with `error TS2322: Type '"App.Models.User.{id}"' is not assignable to type '"projects.{project}"'.`: a broadcast Contract shows `broadcastOn()` sending the event to a channel its `#[BroadcastsOn]` doesn't declare. Declare the pattern, or stop sending it there.
- `ci:check:tsc` fails with `error TS2345: Argument of type '"statuss"' is not assignable to parameter of type '"status" | "projects.{project}.public"'.`: the public channel isn't declared in `config/strictly.php`'s `public_channels`.
- `ci:check:tsc` fails with `' is not assignable to type 'BroadcastChannelName'.`: the event broadcasts on a channel `routes/channels.php` doesn't define, or on a public channel `config/strictly.php` doesn't declare.
- `ci:check:generate` fails with `public_channels.projects.{project}.public.projct: the pattern has no parameter {projct}.`: a `public_channels` declaration binds a model to a parameter its pattern doesn't have.
- `ci:check:generate` fails with `App\Events\ProjectUpdated: #[BroadcastsOn('project.{project}')] names no channel.`: use a pattern `routes/channels.php` defines, or declare the public channel in `config/strictly.php`.
- `ci:check:coverage` fails with `✗ ProjectUpdated: no feature test broadcasts this event: add a feature test that broadcasts App\Events\ProjectUpdated, so its App\Data\ProjectData payload is checked.`: add the step 5 test.
- `ci:check:coverage` fails with `✗ ProjectUpdated on App.Models.User.{id}: no feature test broadcasts this event on this channel: add a feature test that broadcasts App\Events\ProjectUpdated on it, or remove #[BroadcastsOn('App.Models.User.{id}')] if it is never sent there.`: test the broadcast to that channel, or drop the stale declaration.
- `ci:check:coverage` fails with `✗ maintenance: no event's #[BroadcastsOn] names this public channel: declare it on the events sent on it, or remove it from public_channels.`: a public channel nothing is sent on.
