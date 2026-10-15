<?php

namespace StrictlyLaravel\Broadcasting;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Actions\DiscoverTypesAction;
use StrictlyLaravel\Inertia\Page;
use Throwable;

use function Illuminate\Support\enum_value;

/**
 * The app's broadcast events whose payload is a Data class (BroadcastsData, Problem P-052), each by the name Echo
 * listens for it (Problem P-053):
 *
 * - an event without `broadcastAs()` is broadcast under its class name, which Echo takes relative to its default
 *   namespace, `App.Events`: `App\Events\CommentPosted` is `CommentPosted`, `App\Events\Issues\Closed` is
 *   `Issues.Closed`;
 * - any other name (`broadcastAs()` returning `comment.posted`, or a class outside App\Events) is taken as it is,
 *   after a leading dot: `.comment.posted`.
 *
 * That name is what `@/generated/broadcasting` maps to the payload's type and what `useChannelEvent()` takes, so it
 * reads like the name an Echo listener is given.
 */
final class BroadcastEvents
{
    /** Echo's default event namespace, `App.Events`, as a PHP namespace. */
    private const string ECHO_NAMESPACE = 'App\\Events\\';

    /**
     * Every broadcast event in the given directories whose payload is a Data class, by the name Echo listens for it.
     *
     * @param  list<string>  $directories  where the app's classes live (app/)
     * @return array<string, BroadcastEvent>
     *
     * @throws RuntimeException when an event's name depends on its state, so it can't be known without an instance, or
     *                          two events share a name
     */
    public static function in(array $directories): array
    {
        $events = [];

        foreach ((new DiscoverTypesAction)->execute($directories) as $node) {
            $class = $node->getName();

            if ($node->isAbstract() || ! is_subclass_of($class, ShouldBroadcast::class)) {
                continue;
            }

            $payload = self::payloadClass($class);

            if ($payload === null) {
                continue;
            }

            $name = self::staticName($class);
            $key = self::key($name);

            if (isset($events[$key])) {
                throw new RuntimeException(sprintf('%s and %s are both broadcast as %s, so a listener can\'t tell them apart: give one of them another name.', $events[$key]->class, $class, $name));
            }

            $events[$key] = new BroadcastEvent($class, $name, $key, $payload);
        }

        ksort($events);

        return $events;
    }

    /**
     * The event a broadcast event object is, by the name it was broadcast under, or null when its payload isn't a Data
     * class (an event of the framework's, such as a broadcast notification).
     */
    public static function of(ShouldBroadcast $event): ?BroadcastEvent
    {
        $payload = self::payloadClass($event::class);

        if ($payload === null) {
            return null;
        }

        $name = self::name($event);

        return new BroadcastEvent($event::class, $name, self::key($name), $payload);
    }

    /** The name Laravel broadcasts an event under: what its `broadcastAs()` returns, or its class name. */
    public static function name(object $event): string
    {
        if (! method_exists($event, 'broadcastAs')) {
            return $event::class;
        }

        $name = enum_value($event->broadcastAs());

        if (! is_string($name) && ! is_int($name)) {
            throw new RuntimeException(sprintf('%s::broadcastAs() returns no string.', $event::class));
        }

        return (string) $name;
    }

    /**
     * The name Echo listens for a broadcast name under, with its default namespace: `CommentPosted` for
     * `App\Events\CommentPosted`, `.comment.posted` for `comment.posted`.
     */
    public static function key(string $name): string
    {
        return str_starts_with($name, self::ECHO_NAMESPACE)
            ? str_replace('\\', '.', substr($name, strlen(self::ECHO_NAMESPACE)))
            : '.'.$name;
    }

    /**
     * The Data class an event's `broadcastData()` declares it returns (BroadcastsData), or null when the event sends
     * its payload any other way, or declares no single Data class that isn't a Page class.
     *
     * @return class-string<Data>|null
     */
    public static function payloadClass(string $class): ?string
    {
        if (! class_exists($class) || ! in_array(BroadcastsData::class, self::traitsOf($class), true) || ! method_exists($class, 'broadcastData')) {
            return null;
        }

        $type = (new ReflectionMethod($class, 'broadcastData'))->getReturnType();

        if (! $type instanceof ReflectionNamedType || $type->allowsNull() || $type->isBuiltin()) {
            return null;
        }

        $payload = $type->getName();

        return is_subclass_of($payload, Data::class) && ! is_subclass_of($payload, Page::class) ? $payload : null;
    }

    /**
     * The name of an event without an instance of it: its class name, or what `broadcastAs()` returns on an instance
     * whose constructor never ran, which works for a name that doesn't depend on the event's state.
     *
     * @param  class-string  $class
     */
    private static function staticName(string $class): string
    {
        if (! method_exists($class, 'broadcastAs')) {
            return $class;
        }

        try {
            return self::name((new ReflectionClass($class))->newInstanceWithoutConstructor());
        } catch (Throwable $exception) {
            throw new RuntimeException(sprintf(
                '%s::broadcastAs() depends on the event\'s state (%s), so the name Echo listens for can\'t be generated: return a fixed name.',
                $class,
                $exception->getMessage(),
            ), previous: $exception);
        }
    }

    /**
     * Every trait a class uses, its parents' included.
     *
     * @return list<string>
     */
    private static function traitsOf(string $class): array
    {
        return array_values(class_uses_recursive($class));
    }
}
