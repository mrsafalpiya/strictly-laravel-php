<?php

namespace StrictlyLaravel\Install;

use RuntimeException;

/**
 * Registers the npm package's Vite plugin that regenerates the TypeScript types in dev (`@/generated`, when a PHP file
 * under app/ changes or a page component file is created or deleted) in the app's vite.config.ts: imports it after
 * the last import and appends `strictlyLaravel(),` to the `plugins` array (the Base Kit's
 * `plugins: lazyPlugins(() => [ … ])`, or a plain `plugins: [ … ]`).
 */
final class ViteTypesPlugin
{
    public const string MODULE = '@mrsafalpiya/strictly-laravel/vite';

    public const string IMPORT = "import { strictlyLaravel } from '".self::MODULE."';";

    /**
     * Returns whether the file changed.
     */
    public static function register(string $viteConfig): bool
    {
        return ViteConfig::patch($viteConfig, function (string $source): string {
            if (preg_match('/\bstrictlyLaravel\s*\(/', $source) !== 1) {
                $source = self::addToPlugins($source);
            }

            if (preg_match('/^import\s[^;]*[\'"]'.preg_quote(self::MODULE, '/').'[\'"]/m', $source) !== 1) {
                $source = self::addImport($source);
            }

            return $source;
        });
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

        $close = ViteConfig::closing($source, $plugins[0][1] + strlen($plugins[0][0]), '[', ']', 'the `plugins` array');
        $lineStart = strrpos(substr($source, 0, $close), "\n");

        if ($lineStart === false || trim(substr($source, $lineStart + 1, $close - $lineStart - 1)) !== '') {
            throw new RuntimeException("vite.config.ts doesn't close the `plugins` array on a line of its own; {$manually}");
        }

        $entry = $plugins[1][0].'    strictlyLaravel(),'."\n";

        return substr($source, 0, $lineStart + 1).$entry.substr($source, $lineStart + 1);
    }
}
