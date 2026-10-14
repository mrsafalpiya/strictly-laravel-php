<?php

namespace StrictlyLaravel\Install;

use JsonException;
use RuntimeException;
use stdClass;

/**
 * Writes the app's `ci:check` Composer script as a sequence of steps, each one also runnable on its own as
 * `composer ci:check:<step>`: the installer's check that every hook is applied, generation, then the Base Kit's own
 * checks with its own tools, with `tsc` after the tests so that it also checks the Contracts they recorded (Problem
 * P-065).
 */
final class CiCheckScripts
{
    private const string NO_TIMEOUT = 'Composer\\Config::disableProcessTimeout';

    /**
     * Step name => commands.
     *
     * @return array<string, list<string>>
     */
    public static function steps(PackageManager $packageManager): array
    {
        return [
            'install' => ['@php artisan strictly:install --check'], // every hook applied (P-080, P-082)
            'generate' => ['@php artisan strictly:generate'],   // TypeScript from PHP (gitignored): Data, enums, routes
            'lint' => [$packageManager->run('check')],          // Vite+: oxlint (type-aware) and oxfmt
            'test' => ['@test'],                                // Pint, PHPStan (Larastan) and Pest, which records Contracts
            'tsc' => [$packageManager->run('types:check')],     // tsc --noEmit: the app and the Contracts the tests recorded
        ];
    }

    /**
     * Write the scripts into the given composer.json. Returns whether the file changed, and the app's own
     * `ci:check:*` scripts that were replaced or dropped because they differed from ours. (Replacing the Base Kit's
     * `ci:check` itself is the point, so it isn't reported.)
     *
     * @return array{changed: bool, replaced: list<string>}
     */
    public static function write(string $composerJson, PackageManager $packageManager): array
    {
        $original = file_get_contents($composerJson);

        if ($original === false) {
            throw new RuntimeException("Cannot read {$composerJson}");
        }

        try {
            $manifest = json_decode($original, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$composerJson} is not valid JSON: {$e->getMessage()}", previous: $e);
        }

        if (! $manifest instanceof stdClass) {
            throw new RuntimeException("{$composerJson} is not a JSON object");
        }

        $ours = self::scripts($packageManager);
        $existing = $manifest->scripts ?? new stdClass;
        $scripts = self::merge($existing, $ours);
        $replaced = [];

        foreach (get_object_vars($existing) as $name => $commands) {
            $name = (string) $name;

            if (str_starts_with($name, 'ci:check:') && ($ours[$name] ?? null) !== $commands) {
                $replaced[] = $name;
            }
        }
        $span = self::topLevelObjectSpan($original, 'scripts');

        if ($span !== null) {
            // Rewrite only the "scripts" object so the rest of the file keeps its formatting byte for byte.
            [$start, $end, $indent] = $span;
            $updated = substr($original, 0, $start)
                .str_replace("\n", "\n{$indent}", self::encode($scripts))
                .substr($original, $end);
        } else {
            $manifest->scripts = $scripts;
            $updated = self::encode($manifest).(str_ends_with($original, "\n") ? "\n" : '');
        }

        if ($updated === $original) {
            return ['changed' => false, 'replaced' => []];
        }

        file_put_contents($composerJson, $updated);

        return ['changed' => true, 'replaced' => $replaced];
    }

    private static function encode(stdClass $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Where the object value of a top-level key sits in JSON text: [start, end) byte offsets and the indentation of
     * the key's line. Null when the key is absent or its value isn't an object.
     *
     * @return array{int, int, string}|null
     */
    private static function topLevelObjectSpan(string $json, string $key): ?array
    {
        $depth = 0;
        $start = null;

        for ($i = 0, $length = strlen($json); $i < $length; $i++) {
            $char = $json[$i];

            if ($char === '"') {
                $close = self::closingQuote($json, $i);

                if ($start === null && $depth === 1
                    && preg_match('/\G\s*:\s*/', $json, $colon, 0, $close + 1) === 1
                    && json_decode(substr($json, $i, $close - $i + 1)) === $key) {
                    $start = $close + 1 + strlen($colon[0]);

                    if (($json[$start] ?? '') !== '{') {
                        return null;
                    }

                    $lineStart = strrpos(substr($json, 0, $i), "\n");
                    $indent = substr($json, $lineStart === false ? 0 : $lineStart + 1, $i - ($lineStart === false ? 0 : $lineStart + 1));
                    $keyDepth = $depth;
                }

                $i = $close;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;

                if ($start !== null && $depth === $keyDepth) {
                    return [$start, $i + 1, trim($indent) === '' ? $indent : ''];
                }
            }
        }

        return null;
    }

    private static function closingQuote(string $json, int $open): int
    {
        for ($i = $open + 1, $length = strlen($json); $i < $length; $i++) {
            if ($json[$i] === '\\') {
                $i++;
            } elseif ($json[$i] === '"') {
                return $i;
            }
        }

        throw new RuntimeException('Unterminated string in composer.json');
    }

    /**
     * @return array<string, list<string>>
     */
    private static function scripts(PackageManager $packageManager): array
    {
        $steps = self::steps($packageManager);
        $scripts = ['ci:check' => [self::NO_TIMEOUT, ...array_map(fn (string $step) => "@ci:check:{$step}", array_keys($steps))]];

        foreach ($steps as $step => $commands) {
            $scripts["ci:check:{$step}"] = [self::NO_TIMEOUT, ...$commands];
        }

        return $scripts;
    }

    /**
     * Replace `ci:check` in place (appending it when absent), put its steps right after it and drop stale steps.
     *
     * @param  array<string, list<string>>  $ours
     */
    private static function merge(stdClass $existing, array $ours): stdClass
    {
        $merged = new stdClass;

        foreach (get_object_vars($existing) as $name => $commands) {
            $name = (string) $name;

            if (str_starts_with($name, 'ci:check:')) {
                continue;
            }

            if ($name === 'ci:check') {
                foreach ($ours as $ourName => $ourCommands) {
                    $merged->{$ourName} = $ourCommands;
                }

                continue;
            }

            $merged->{$name} = $commands;
        }

        foreach ($ours as $ourName => $ourCommands) {
            $merged->{$ourName} ??= $ourCommands;
        }

        return $merged;
    }
}
