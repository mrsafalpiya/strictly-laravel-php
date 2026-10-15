---
name: strictly-add-broadcast-event
description: "Adds a broadcast event the Strictly Laravel way: an event whose payload is a Data class, a channel defined in routes/channels.php, a typed useChannelEvent listener in React, and a feature test that broadcasts it. Use when adding real-time updates, a ShouldBroadcast event, a private, presence or public channel, or listening for an event with Echo."
---
# Add a broadcast event

The worked example broadcasts `ProjectUpdated` to everyone viewing a project when its owner renames it, and updates the title live.

## 0. Once per app: install broadcasting

If `routes/channels.php` doesn't exist, run Laravel's installer, then install Echo's React bindings:

```bash
php artisan install:broadcasting --reverb
pnpm add -D laravel-echo pusher-js @laravel/echo-react
```

List every `VITE_REVERB_*` variable it adds in `.env.example` (a `VITE_*` variable missing there fails `tsc`, and one unset in a build's environment fails the build). Replace the stub's untyped `App.Models.User.{id}` channel with typed ones like step 1's.

## 1. Define the channel with a typed callback

A parameter named like the pattern's and type-hinted with a model binds it; return `bool` for a private channel, `array` for a presence channel:

```php title="routes/channels.php"
Broadcast::channel('projects.{project}', fn (User $user, Project $project): bool => $project->user_id === $user->id);
```

A public channel (`new Channel('status')`, which guests can listen on too) has no definition in `routes/channels.php`. Declare it in `config/strictly.php`'s `public_channels` instead, by its name, or by its pattern keyed by itself with the models its parameters hold the route key of:

```php
'public_channels' => [
    'status',
    'projects.{project}.public' => ['project' => Project::class],
],
```

## 2. Write the event with a Data class payload

The payload is a Data class (here `ProjectData`, built with `fromModel()`) that is the same for every listener. The event implements `ShouldBroadcast`, uses `BroadcastsData`, and declares the payload class as `broadcastData()`'s return type:

```php title="app/Events/ProjectUpdated.php"
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

`channel()` takes only the patterns `routes/channels.php` defines, with their parameters (a Data object or its route key); the event name is the one Echo listens for (`ProjectUpdated` for `App\Events\ProjectUpdated`, `Projects.Renamed` for `App\Events\Projects\Renamed`); the callback receives the payload's type:

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

A broadcast notification (`toBroadcast()`) or a model broadcast (`BroadcastsEvents`) has no generated payload type, so don't read it with Echo's `useEchoNotification()` or `useEchoModel()`. Broadcast an app event like `ProjectUpdated` alongside the notification or model change, and listen for that with `useChannelEvent()`.

## 5. Broadcast every payload shape in a feature test

Every event a feature test broadcasts is recorded as a Contract (its payload, and its channels against `routes/channels.php` and the public channels `config/strictly.php` declares). A test must broadcast each event, with each nullable payload field both `null` and set, or `ci:check:coverage` fails. Spy with `Event::listen()`; `Event::fake()` stops the broadcast, so nothing is recorded:

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

Done when `composer ci:check` passes. `php artisan reverb:start` serves the events in development.

## When a check fails

- `ci:check:test` fails with `Broadcast event App\Events\ProjectUpdated sends a payload that has no`: return a Data class from `broadcastData()` instead of public properties or `broadcastWith()`.
- `ci:check:lint` fails with ``Echo's `useEcho()` takes any event name and a hand-typed payload``: listen with `useChannelEvent()` from `@/generated/broadcasting`.
- `ci:check:lint` fails with ``Echo's `useEchoNotification()` reads a broadcast notification's hand-typed payload``: broadcast an app event with a `BroadcastsData` payload alongside the notification and listen for it with `useChannelEvent()`; the same for `useEchoModel()` and a model change.
- `ci:check:tsc` fails with `error TS2345: Argument of type '"project.{project}"' is not assignable to parameter of type '"projects.{project}"'.`: the pattern isn't defined in `routes/channels.php`.
- `ci:check:tsc` fails with `error TS2345: Argument of type '"ProjectUpdatd"' is not assignable to parameter of type '"ProjectUpdated"'.`: use the event's Echo name.
- `ci:check:tsc` fails with `error TS2339: Property 'title' does not exist on type 'ProjectData'.`: the callback reads a field the payload class doesn't have.
- `ci:check:tsc` fails with `error TS2345: Argument of type '"statuss"' is not assignable to parameter of type '"status" | "projects.{project}.public"'.`: the public channel isn't declared in `config/strictly.php`'s `public_channels`.
- `ci:check:tsc` fails with `' is not assignable to type 'BroadcastChannelName'.`: the event broadcasts on a channel `routes/channels.php` doesn't define, or on a public channel `config/strictly.php` doesn't declare.
- `ci:check:generate` fails with `public_channels.projects.{project}.public.projct: the pattern has no parameter {projct}.`: a `public_channels` declaration binds a model to a parameter its pattern doesn't have.
- `ci:check:coverage` fails with `✗ ProjectUpdated: no feature test broadcasts this event: add a feature test that broadcasts App\Events\ProjectUpdated, so its App\Data\ProjectData payload is checked.`: add the step 5 test.
