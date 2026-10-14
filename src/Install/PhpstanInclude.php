<?php

namespace StrictlyLaravel\Install;

use RuntimeException;

/**
 * Makes the app's phpstan.neon include the package's PHPStan extension. Kit Conversion writes the include into a
 * phpstan.neon it converts; this covers one it skipped because the app had edited it, so the extension still applies.
 * The app's own includes of a file the extension already includes (the Base Kit includes Larastan and Carbon) are
 * removed, since PHPStan refuses a file included twice.
 */
final class PhpstanInclude
{
    public const string EXTENSION = 'vendor/mrsafalpiya/strictly-laravel/extension.neon';

    private const string EXTENSION_FILE = __DIR__.'/../../extension.neon';

    /**
     * Returns the includes removed as redundant, or null when phpstan.neon already includes the extension.
     *
     * @return list<string>|null
     */
    public static function ensure(string $phpstanNeon): ?array
    {
        if (! is_file($phpstanNeon)) {
            throw new RuntimeException('phpstan.neon not found: create it with `includes: ['.self::EXTENSION.']`');
        }

        $original = (string) file_get_contents($phpstanNeon);

        if (str_contains($original, self::EXTENSION)) {
            return null;
        }

        $lines = explode("\n", $original);
        $key = array_search('includes:', array_map(rtrim(...), $lines), true);

        if ($key === false) {
            if (preg_match('/^includes\s*:/m', $original) === 1) {
                throw new RuntimeException('phpstan.neon lists its includes inline; add '.self::EXTENSION.' to them by hand');
            }

            self::write($phpstanNeon, "includes:\n    - ".self::EXTENSION."\n\n".$original);

            return [];
        }

        $alreadyIncluded = self::extensionIncludes();
        $indent = '    ';
        $removed = [];
        $kept = [];

        for ($i = $key + 1; $i < count($lines) && preg_match('/^(\s+)-\s*(.+?)\s*$/', $lines[$i], $item) === 1; $i++) {
            $indent = $item[1];
            $path = trim($item[2], '\'"');

            foreach ($alreadyIncluded as $tail) {
                if (str_ends_with($path, '/'.$tail)) {
                    $removed[] = $path;

                    continue 2;
                }
            }

            $kept[] = $lines[$i];
        }

        array_splice($lines, $key + 1, $i - $key - 1, ["{$indent}- ".self::EXTENSION, ...$kept]);
        self::write($phpstanNeon, implode("\n", $lines));

        return $removed;
    }

    /**
     * The files the extension includes, as the end of their path (`larastan/larastan/extension.neon`,
     * `conf/bleedingEdge.neon`), which is how an app's own include of the same file ends.
     *
     * @return list<string>
     */
    private static function extensionIncludes(): array
    {
        preg_match_all('/^\s+-\s*(?:%rootDir%(?:\/\.\.)+|phar:\/\/phpstan\.phar)\/(\S+)\s*$/m', (string) file_get_contents(self::EXTENSION_FILE), $matches);

        return $matches[1];
    }

    private static function write(string $file, string $content): void
    {
        if (file_put_contents($file, $content) === false) {
            throw new RuntimeException("Cannot write {$file}");
        }
    }
}
