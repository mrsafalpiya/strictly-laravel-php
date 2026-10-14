<?php

namespace StrictlyLaravel\Install;

use FilesystemIterator;
use RecursiveCallbackFilterIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * A throwaway copy of the app that `strictly:install --check` applies every hook to, so it can tell what the installer
 * would change without changing the app. Dependencies, VCS data, storage and build output are left out: no hook reads
 * or writes them. Symlinks are copied as symlinks.
 */
final class ScratchCopy
{
    /** App-relative paths that aren't copied. A hook must not edit a file under one of them. */
    public const array EXCLUDED = ['.git', 'vendor', 'node_modules', 'storage', 'bootstrap/cache', 'public/build', 'public/hot'];

    /**
     * Copy $app into a new temporary directory and return its path; remove() it when done.
     */
    public static function create(string $app): string
    {
        $copy = sys_get_temp_dir().'/strictly-install-check-'.bin2hex(random_bytes(6));

        if (! mkdir($copy, 0700)) {
            throw new RuntimeException("Cannot create {$copy}");
        }

        $entries = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($app, FilesystemIterator::SKIP_DOTS),
                fn (SplFileInfo $entry): bool => ! in_array(substr($entry->getPathname(), strlen($app) + 1), self::EXCLUDED, true),
            ),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var SplFileInfo $entry */
            $relative = substr($entry->getPathname(), strlen($app) + 1);
            $target = "{$copy}/{$relative}";

            $copied = match (true) {
                $entry->isLink() => symlink((string) readlink($entry->getPathname()), $target),
                $entry->isDir() => mkdir($target, 0755),
                default => copy($entry->getPathname(), $target),
            };

            if (! $copied) {
                throw new RuntimeException("Cannot copy {$entry->getPathname()} to {$target}");
            }
        }

        return $copy;
    }

    public static function remove(string $copy): void
    {
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($copy, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($entries as $entry) {
            /** @var SplFileInfo $entry */
            $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }

        rmdir($copy);
    }
}
