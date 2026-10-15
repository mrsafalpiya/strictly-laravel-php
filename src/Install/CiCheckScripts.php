<?php

namespace StrictlyLaravel\Install;

use stdClass;

/**
 * Writes the app's `ci:check` Composer script as a sequence of steps, each one also runnable on its own as
 * `composer ci:check:<step>`: the installer's check that every hook is applied, generation, then the Base Kit's own
 * checks with its own tools, then Contract Coverage over the Contracts the tests recorded (Problem P-066), with `tsc`
 * last so that it also checks those Contracts (Problem P-065).
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
            'coverage' => ['@php artisan strictly:coverage --strict'], // Contract Coverage: what the Contracts never exercised (P-066)
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
        $ours = self::scripts($packageManager);
        $replaced = [];

        $changed = ComposerScripts::rewrite($composerJson, function (stdClass $existing) use ($ours, &$replaced): stdClass {
            foreach (get_object_vars($existing) as $name => $commands) {
                $name = (string) $name;

                if (str_starts_with($name, 'ci:check:') && ($ours[$name] ?? null) !== $commands) {
                    $replaced[] = $name;
                }
            }

            return self::merge($existing, $ours);
        });

        return ['changed' => $changed, 'replaced' => $changed ? $replaced : []];
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
