<?php

namespace StrictlyLaravel\Install;

use RuntimeException;

/**
 * The app's vite.config.ts, which the installer edits as text because it is code: ViteLintPlugin and ViteTypesPlugin
 * each patch it, in the Base Kit's formatting so `vp check` stays clean.
 */
final class ViteConfig
{
    /**
     * Apply $patch to the file's contents and write the result when it differs. Returns whether the file changed.
     *
     * @param  callable(string): string  $patch
     */
    public static function patch(string $viteConfig, callable $patch): bool
    {
        if (! is_file($viteConfig)) {
            throw new RuntimeException('vite.config.ts not found');
        }

        $original = (string) file_get_contents($viteConfig);
        $patched = $patch($original);

        if ($patched === $original) {
            return false;
        }

        if (file_put_contents($viteConfig, $patched) === false) {
            throw new RuntimeException("Cannot write {$viteConfig}");
        }

        return true;
    }

    /**
     * The offset of the $close that ends the block whose body starts at $start, counting nested $open/$close pairs
     * (delimiters inside strings and comments aren't expected in the config's plugin list or lint object).
     *
     * @param  string  $what  the block, for the error when it isn't closed, such as "the `plugins` array"
     */
    public static function closing(string $source, int $start, string $open, string $close, string $what): int
    {
        $depth = 1;

        for ($i = $start; $i < strlen($source); $i++) {
            $depth += match ($source[$i]) {
                $open => 1,
                $close => -1,
                default => 0,
            };

            if ($depth === 0) {
                return $i;
            }
        }

        throw new RuntimeException("vite.config.ts: {$what} is not closed");
    }
}
