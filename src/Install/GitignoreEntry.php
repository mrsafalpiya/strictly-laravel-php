<?php

namespace StrictlyLaravel\Install;

use RuntimeException;

/**
 * Adds a line to the app's .gitignore unless it is already there.
 */
final class GitignoreEntry
{
    /**
     * Returns whether the file changed.
     */
    public static function ensure(string $gitignore, string $entry): bool
    {
        $original = is_file($gitignore) ? file_get_contents($gitignore) : '';

        if ($original === false) {
            throw new RuntimeException("Cannot read {$gitignore}");
        }

        $lines = preg_split('/\R/', $original);
        $lines = $lines === false ? [] : array_map(trim(...), $lines);

        if (in_array($entry, $lines, true)) {
            return false;
        }

        $separator = $original === '' || str_ends_with($original, "\n") ? '' : "\n";
        file_put_contents($gitignore, "{$original}{$separator}{$entry}\n");

        return true;
    }
}
