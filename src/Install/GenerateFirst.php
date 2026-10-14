<?php

namespace StrictlyLaravel\Install;

use JsonException;
use RuntimeException;

/**
 * Makes the app's package.json `build`, `build:ssr` and `types:check` scripts run `php artisan strictly:generate`
 * first, so they work from a fresh clone, where the gitignored generated TypeScript doesn't exist yet. Only the script
 * values change; the rest of package.json keeps its formatting byte for byte.
 */
final class GenerateFirst
{
    public const string PREFIX = 'php artisan strictly:generate && ';

    /** The scripts that read generated types. `build:ssr` is optional; the others are the Base Kit's. */
    private const array SCRIPTS = ['build' => true, 'build:ssr' => false, 'types:check' => true];

    /**
     * Returns whether the file changed.
     */
    public static function ensure(string $packageJson): bool
    {
        $original = file_get_contents($packageJson);

        if ($original === false) {
            throw new RuntimeException("Cannot read {$packageJson}");
        }

        try {
            $manifest = json_decode($original, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$packageJson} is not valid JSON: {$e->getMessage()}", previous: $e);
        }

        $scripts = is_array($manifest) && is_array($manifest['scripts'] ?? null) ? $manifest['scripts'] : [];
        $updated = $original;

        foreach (self::SCRIPTS as $name => $required) {
            $command = $scripts[$name] ?? null;

            if (! is_string($command)) {
                if ($required) {
                    throw new RuntimeException("package.json has no \"{$name}\" script to run strictly:generate before");
                }

                continue;
            }

            if (str_starts_with($command, self::PREFIX)) {
                continue;
            }

            $pattern = '/('.preg_quote(self::encode($name), '/').'\s*:\s*)'.preg_quote(self::encode($command), '/').'/';
            $replacement = self::encode(self::PREFIX.$command);
            $updated = preg_replace_callback($pattern, fn (array $match): string => $match[1].$replacement, $updated, 1, $count);

            if ($updated === null || $count !== 1) {
                throw new RuntimeException("Cannot find the \"{$name}\" script in {$packageJson} to run strictly:generate before it");
            }
        }

        if ($updated === $original) {
            return false;
        }

        file_put_contents($packageJson, $updated);

        return true;
    }

    private static function encode(string $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
