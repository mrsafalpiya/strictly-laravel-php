<?php

namespace StrictlyLaravel\Testing;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Http\FormRequest;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * The request input keys the app read while handling the current request (Problem P-024): `input('notify')`,
 * `boolean('notify')`, `only('a', 'b')`, `has('a')`, `$request->notify`, … A feature test's request is a
 * TrackedRequest (RecordsContracts), whose accessors report here. ContractRecorder checks the keys against the
 * action's input type.
 *
 * A FormRequest is a request object of its own, built from the test's request when an action takes one, so its reads
 * would go unseen: trackFormRequests() makes the container build a subclass of each one that reports them too
 * (TracksInputReads). That is how a vendor action's reads are seen (Problem P-025): Fortify reads its login payload
 * through its own LoginRequest. A final FormRequest can't be subclassed, so its reads stay unseen (Problem P-026).
 *
 * Reading the whole input (`all()`, `input()`, `except()`) names no key, so it records nothing (Problem P-026).
 * Framework plumbing is never recorded:
 *
 * - keys starting with `_`, the protocol's own (`_token`, `_method`, `_error_bag`);
 * - reads by framework code that owns its parameter: the paginator's `page` and `cursor`, which it builds its links
 *   from, and laravel-data's `include`, `exclude`, `only` and `except` response partials (READERS).
 */
final class InputReads
{
    /** The framework code whose reads are plumbing, by namespace. */
    private const array READERS = ['Illuminate\\Pagination\\', 'Spatie\\LaravelData\\'];

    /** @var list<string> */
    private static array $keys = [];

    /**
     * Build every FormRequest the app resolves from now on as a subclass that reports its reads, unless the app bound
     * the class itself or it is final.
     */
    public static function trackFormRequests(Application $app): void
    {
        $app->beforeResolving(function (mixed $abstract) use ($app): void {
            if (! is_string($abstract) || ! is_subclass_of($abstract, FormRequest::class) || $app->bound($abstract)) {
                return;
            }

            $tracked = self::trackedSubclass($abstract);

            if ($tracked !== null) {
                $app->bind($abstract, $tracked);
            }
        });
    }

    /**
     * A subclass of the FormRequest that uses TracksInputReads, declared once per class.
     *
     * @param  class-string<FormRequest>  $class
     * @return class-string<FormRequest>|null
     */
    private static function trackedSubclass(string $class): ?string
    {
        $reflection = new ReflectionClass($class);

        if ($reflection->isFinal() || $reflection->isAbstract() || in_array(TracksInputReads::class, class_uses_recursive($class), true)) {
            return null;
        }

        $name = 'Tracked'.substr(sha1($class), 0, 16);
        $tracked = __NAMESPACE__.'\\Tracked\\'.$name;

        if (! class_exists($tracked, false)) {
            // A class extending an arbitrary FormRequest can only be declared at runtime.
            eval(sprintf('namespace %s\Tracked; final class %s extends \%s { use \%s; }', __NAMESPACE__, $name, $class, TracksInputReads::class));
        }

        /** @var class-string<FormRequest> $tracked */
        return $tracked;
    }

    /** Forget the keys of the previous request: called as a request reaches its route. */
    public static function reset(): void
    {
        self::$keys = [];
    }

    /**
     * Record keys read by the code that called the request's accessor.
     *
     * @param  array<mixed>  $keys
     */
    public static function record(array $keys): void
    {
        $keys = array_values(array_filter($keys, fn (mixed $key): bool => is_string($key) && $key !== '' && ! str_starts_with($key, '_')));

        if ($keys === [] || self::readByFramework()) {
            return;
        }

        array_push(self::$keys, ...$keys);
    }

    /**
     * The keys read since the request reached its route.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_values(array_unique(self::$keys));
    }

    /** Whether the code that called the request's accessor is framework plumbing. */
    private static function readByFramework(): bool
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16) as $frame) {
            $class = $frame['class'] ?? null;

            if ($class === self::class || ($class !== null && is_a($class, SymfonyRequest::class, true))) {
                continue; // inside the request's own accessors (`boolean()` reads through `input()`)
            }

            return $class !== null && array_any(self::READERS, fn (string $namespace): bool => str_starts_with($class, $namespace));
        }

        return false;
    }
}
