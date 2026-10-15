<?php

namespace StrictlyLaravel\Testing;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * The request input keys the app read while handling the current request (Problem P-024): `input('notify')`,
 * `boolean('notify')`, `only('a', 'b')`, `has('a')`, `$request->notify`, … A feature test's request is a
 * TrackedRequest (RecordsContracts), whose accessors report here. ContractRecorder checks the keys against the
 * action's input type.
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
