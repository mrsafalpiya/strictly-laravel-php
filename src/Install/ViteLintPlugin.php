<?php

namespace StrictlyLaravel\Install;

use RuntimeException;

/**
 * Registers the npm package's oxlint plugin in the Base Kit's Vite+ lint config (the `lint` object in vite.config.ts)
 * and turns each of its rules on as an error: adds the plugin to `jsPlugins` and any missing rule to `rules`, creating
 * either key when the app has none. vite.config.ts is code, so it is edited as text within the `lint: { … }` object,
 * written in the Base Kit's formatting so `vp check` stays clean. Rules are never turned off (ADR-0007): an app
 * suppresses one with a reasoned comment instead.
 */
final class ViteLintPlugin
{
    public const string PLUGIN = '@mrsafalpiya/strictly-laravel/oxlint';

    /** The plugin's rules, all on as errors. */
    public const array RULES = ['strictly/no-hardcoded-urls', 'strictly/require-suppression-reason'];

    /**
     * Returns whether the file changed.
     */
    public static function register(string $viteConfig): bool
    {
        if (! is_file($viteConfig)) {
            throw new RuntimeException('vite.config.ts not found');
        }

        $original = (string) file_get_contents($viteConfig);

        if (preg_match('/^([ \t]*)lint:\s*\{[ \t]*\n/m', $original, $lint, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException('vite.config.ts has no `lint: {` object; add `jsPlugins: [\''.self::PLUGIN.'\']` and its rules to the Vite+ lint config by hand');
        }

        $start = $lint[0][1] + strlen($lint[0][0]);
        $end = self::closingBrace($original, $start);
        $indent = $lint[1][0].'    ';
        $block = substr($original, $start, $end - $start);
        $added = '';

        if (! str_contains($block, "'".self::PLUGIN."'")) {
            $count = 0;
            $block = (string) preg_replace('/\bjsPlugins:\s*\[/', '$0\''.self::PLUGIN.'\', ', $block, 1, $count);

            if ($count === 0) {
                $added .= "{$indent}jsPlugins: ['".self::PLUGIN."'],\n";
            }
        }

        $missing = array_values(array_filter(self::RULES, fn (string $rule): bool => ! str_contains($block, "'{$rule}'")));
        $ruleLines = implode('', array_map(fn (string $rule): string => "{$indent}    '{$rule}': 'error',\n", $missing));

        if ($missing !== []) {
            $count = 0;
            $block = (string) preg_replace('/^([ \t]*)rules:\s*\{[ \t]*\n/m', '$0'.str_replace('$', '\$', $ruleLines), $block, 1, $count);

            if ($count === 0) {
                $added .= "{$indent}rules: {\n{$ruleLines}{$indent}},\n";
            }
        }

        $patched = substr($original, 0, $start).$added.$block.substr($original, $end);

        if ($patched === $original) {
            return false;
        }

        if (file_put_contents($viteConfig, $patched) === false) {
            throw new RuntimeException("Cannot write {$viteConfig}");
        }

        return true;
    }

    /**
     * The offset of the `}` that closes the object whose body starts at $start (braces inside strings and comments
     * aren't expected in a lint config).
     */
    private static function closingBrace(string $source, int $start): int
    {
        $depth = 1;

        for ($i = $start; $i < strlen($source); $i++) {
            $depth += match ($source[$i]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($depth === 0) {
                return $i;
            }
        }

        throw new RuntimeException('vite.config.ts: the `lint: {` object is not closed');
    }
}
