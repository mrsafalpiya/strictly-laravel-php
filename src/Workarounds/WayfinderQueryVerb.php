<?php

namespace StrictlyLaravel\Workarounds;

use Illuminate\Console\Events\CommandFinished;

/**
 * Problem P-001 (docs/problems.md). Upstream: https://github.com/laravel/wayfinder/issues/326
 *
 * Laravel 13 added the QUERY verb, so `Route::redirect()` / `Route::any()` routes (the Base Kit's `settings`
 * redirect) list "query", but the `type Method` union Wayfinder 0.1.x copies into resources/js/wayfinder/index.ts
 * lacks it and `tsc` fails on the generated actions. After every `wayfinder:generate` (Vite plugin, CLI or the
 * installer) this adds "query" to the union. Once upstream adds it the patch is a no-op; then delete this class.
 */
final class WayfinderQueryVerb
{
    public static function afterCommand(CommandFinished $event): void
    {
        if ($event->command !== 'wayfinder:generate' || $event->exitCode !== 0) {
            return;
        }

        $path = $event->input->hasOption('path') ? $event->input->getOption('path') : null;

        self::patch((is_string($path) && $path !== '' ? $path : resource_path('js')).'/wayfinder/index.ts');
    }

    /**
     * Returns whether the file changed.
     */
    public static function patch(string $wayfinderIndex): bool
    {
        if (! is_file($wayfinderIndex)) {
            return false;
        }

        $original = (string) file_get_contents($wayfinderIndex);

        $patched = preg_replace_callback(
            '/^type Method = ([^;\n]+);$/m',
            fn (array $match) => str_contains($match[1], '"query"') ? $match[0] : "type Method = {$match[1]} | \"query\";",
            $original,
        );

        if ($patched === null || $patched === $original) {
            return false;
        }

        file_put_contents($wayfinderIndex, $patched);

        return true;
    }
}
