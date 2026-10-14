<?php

namespace StrictlyLaravel\Testing;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;

/**
 * The request of every feature test (RecordsContracts): a plain Laravel request that reports every input key the app
 * reads by name to InputReads (Problem P-024). The methods not overridden here read through these: `boolean()`,
 * `string()`, `integer()`, `date()`, `enum()`, `filled()` and `whenFilled()` through `input()`; `exists()`,
 * `missing()` and `whenHas()` through `has()`; `$request['key']` through `__get()`. A JSON body is read through
 * `input()` too.
 */
final class TrackedRequest extends Request
{
    /**
     * @param  string|null  $key
     */
    public function input($key = null, $default = null): mixed
    {
        InputReads::record([$key]);

        return parent::input($key, $default);
    }

    /**
     * @param  mixed  $keys
     * @return array<array-key, mixed>
     */
    public function all($keys = null): array
    {
        InputReads::record($keys === null ? [] : (is_array($keys) ? $keys : func_get_args()));

        return parent::all(...func_get_args());
    }

    /**
     * @param  mixed  $keys
     * @return array<array-key, mixed>
     */
    public function only($keys): array
    {
        InputReads::record(is_array($keys) ? $keys : func_get_args());

        return parent::only(...func_get_args());
    }

    /**
     * @param  string|array<array-key, mixed>  $key
     */
    public function has($key): bool
    {
        $keys = is_array($key) ? $key : func_get_args();
        InputReads::record($keys);

        return parent::has($keys);
    }

    /**
     * @param  string|array<array-key, mixed>  $keys
     */
    public function hasAny($keys): bool
    {
        $keys = is_array($keys) ? $keys : func_get_args();
        InputReads::record($keys);

        return parent::hasAny($keys);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        InputReads::record([$key]);

        return parent::get($key, $default);
    }

    /**
     * @param  string|null  $key
     * @param  string|array<array-key, mixed>|null  $default
     * @return string|array<array-key, mixed>|null
     */
    public function query($key = null, $default = null)
    {
        InputReads::record([$key]);

        return parent::query($key, $default);
    }

    /**
     * @param  string|null  $key
     * @param  string|array<array-key, mixed>|null  $default
     * @return string|array<array-key, mixed>|null
     */
    public function post($key = null, $default = null)
    {
        InputReads::record([$key]);

        return parent::post($key, $default);
    }

    /**
     * @param  string|null  $key
     */
    public function file($key = null, $default = null): mixed
    {
        InputReads::record([$key]);

        return parent::file($key, $default);
    }

    /**
     * @param  string|null  $key
     */
    public function json($key = null, $default = null): mixed
    {
        InputReads::record([$key]);

        return parent::json($key, $default);
    }

    /**
     * @param  string  $offset
     */
    public function offsetExists($offset): bool
    {
        $this->recordProperty($offset);

        return parent::offsetExists($offset);
    }

    /**
     * @param  string  $key
     */
    public function __get($key): mixed
    {
        $this->recordProperty($key);

        return parent::__get($key);
    }

    /** `$request->notify` reads the input, `$request->project` the route parameter. */
    private function recordProperty(mixed $key): void
    {
        $route = $this->route();

        if (! ($route instanceof Route && is_string($key) && $route->hasParameter($key))) {
            InputReads::record([$key]);
        }
    }
}
