<?php

namespace StrictlyLaravel\Workarounds;

use ParseError;
use PhpToken;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Problem P-064 (docs/problems.md). Upstream: no upstream issue filed (spatie/php-structure-discoverer).
 *
 * typescript-transformer finds the app's classes with spatie/php-structure-discoverer, which reads a PHP file that
 * doesn't parse as if it were empty (`TokenCollection::fromCode()` swallows the `ParseError`). So a Data class saved
 * half-written loses its type, generation still succeeds, and every component that uses the type breaks. Checking the
 * files first lets `strictly:generate` fail instead and leave the last good output in place, which is what the Vite
 * plugin relies on. Once upstream reports such a file instead of skipping it, delete this class.
 */
final class UnparsableFiles
{
    /**
     * The parse error of every PHP file in the given directories (or files), as `<path>:<line>: <message>` with the
     * path relative to $basePath.
     *
     * @param  iterable<string>  $paths
     * @return list<string>
     */
    public static function in(iterable $paths, string $basePath): array
    {
        $errors = [];

        foreach ($paths as $path) {
            foreach (self::phpFiles($path) as $file) {
                try {
                    PhpToken::tokenize((string) file_get_contents($file), TOKEN_PARSE);
                } catch (ParseError $e) {
                    $relative = str_starts_with($file, $basePath.DIRECTORY_SEPARATOR) ? substr($file, strlen($basePath) + 1) : $file;
                    $errors[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative).":{$e->getLine()}: {$e->getMessage()}";
                }
            }
        }

        // A file can be in more than one of the paths (the generator watches app/ for its transformers and providers).
        $errors = array_values(array_unique($errors));
        sort($errors);

        return $errors;
    }

    /**
     * @return iterable<string>
     */
    private static function phpFiles(string $path): iterable
    {
        if (is_file($path)) {
            if (str_ends_with($path, '.php')) {
                yield $path;
            }

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        /** @var iterable<SplFileInfo> $files */
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                yield $file->getPathname();
            }
        }
    }
}
