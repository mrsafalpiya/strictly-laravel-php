<?php

namespace StrictlyLaravel\Install;

use RuntimeException;

/**
 * Registers the npm package's Vite plugin in the app's vite.config.ts, so the dev server regenerates the TypeScript
 * types when a PHP file under app/ changes: imports it after the last import and appends `strictlyLaravel(),` to the
 * `plugins` array (the Base Kit's `plugins: lazyPlugins(() => [ … ])`, or a plain `plugins: [ … ]`). vite.config.ts is
 * code, so it is edited as text, in the Base Kit's formatting so `vp check` stays clean.
 */
final class VitePluginEntry
{
    public const string MODULE = '@mrsafalpiya/strictly-laravel/vite';

    public const string IMPORT = "import { strictlyLaravel } from '".self::MODULE."';";

    /**
     * Returns whether the file changed.
     */
    public static function register(string $viteConfig): bool
    {
        if (! is_file($viteConfig)) {
            throw new RuntimeException('vite.config.ts not found');
        }

        $original = (string) file_get_contents($viteConfig);
        $patched = $original;

        if (preg_match('/\bstrictlyLaravel\s*\(/', $patched) !== 1) {
            $patched = self::addToPlugins($patched);
        }

        if (preg_match('/^import\s[^;]*[\'"]'.preg_quote(self::MODULE, '/').'[\'"]/m', $patched) !== 1) {
            $patched = self::addImport($patched);
        }

        if ($patched === $original) {
            return false;
        }

        if (file_put_contents($viteConfig, $patched) === false) {
            throw new RuntimeException("Cannot write {$viteConfig}");
        }

        return true;
    }

    private static function addImport(string $source): string
    {
        if (preg_match_all('/^import\s[^;]*;[ \t]*\n/m', $source, $imports, PREG_OFFSET_CAPTURE) === 0) {
            return self::IMPORT."\n".$source;
        }

        $last = end($imports[0]);
        $end = $last[1] + strlen($last[0]);

        return substr($source, 0, $end).self::IMPORT."\n".substr($source, $end);
    }

    private static function addToPlugins(string $source): string
    {
        $manually = 'add `'.self::IMPORT.'` and `strictlyLaravel()` to the Vite plugins by hand';

        if (preg_match('/^([ \t]*)plugins:\s*(?:lazyPlugins\(\s*\(\)\s*=>\s*)?\[[ \t]*\n/m', $source, $plugins, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException("vite.config.ts has no `plugins: [` array written over several lines; {$manually}");
        }

        $close = self::closingBracket($source, $plugins[0][1] + strlen($plugins[0][0]));
        $lineStart = strrpos(substr($source, 0, $close), "\n");

        if ($lineStart === false || trim(substr($source, $lineStart + 1, $close - $lineStart - 1)) !== '') {
            throw new RuntimeException("vite.config.ts doesn't close the `plugins` array on a line of its own; {$manually}");
        }

        $entry = $plugins[1][0].'    strictlyLaravel(),'."\n";

        return substr($source, 0, $lineStart + 1).$entry.substr($source, $lineStart + 1);
    }

    /**
     * The offset of the `]` that closes the array whose body starts at $start (brackets inside strings and comments
     * aren't expected in a plugin list).
     */
    private static function closingBracket(string $source, int $start): int
    {
        $depth = 1;

        for ($i = $start; $i < strlen($source); $i++) {
            $depth += match ($source[$i]) {
                '[' => 1,
                ']' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return $i;
            }
        }

        throw new RuntimeException('vite.config.ts: the `plugins` array is not closed');
    }
}
