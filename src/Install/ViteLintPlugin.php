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

        if (! self::mentions($block, self::PLUGIN)) {
            [$block, $edited] = self::addToFirst($block, [
                '/\bjsPlugins:\s*\[\s*\]/' => "jsPlugins: ['".self::PLUGIN."']",
                '/^([ \t]*)jsPlugins:\s*\[[ \t]*\n/m' => "\$0\$1    '".self::PLUGIN."',\n",
                '/\bjsPlugins:\s*\[/' => "\$0'".self::PLUGIN."', ",
            ]);

            if (! $edited) {
                $added .= "{$indent}jsPlugins: ['".self::PLUGIN."'],\n";
            }
        }

        $missing = array_values(array_filter(self::RULES, fn (string $rule): bool => ! self::mentions($block, $rule)));

        if ($missing !== []) {
            $ruleLines = implode('', array_map(fn (string $rule): string => "{$indent}    '{$rule}': 'error',\n", $missing));
            [$block, $edited] = self::addToFirst($block, [
                '/\brules:\s*\{\s*\}/' => "rules: {\n{$ruleLines}{$indent}}",
                '/^[ \t]*rules:\s*\{[ \t]*\n/m' => '$0'.$ruleLines,
            ]);

            if (! $edited && preg_match('/\brules\s*:/', $block) === 1) {
                throw new RuntimeException('vite.config.ts writes lint.rules on one line; add '.implode(', ', array_map(fn (string $rule): string => "'{$rule}': 'error'", $missing)).' to it by hand');
            }

            if (! $edited) {
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

    /** Whether the lint config names $value as a single- or double-quoted string. */
    private static function mentions(string $block, string $value): bool
    {
        return preg_match('/([\'"])'.preg_quote($value, '/').'\1/', $block) === 1;
    }

    /**
     * Apply the first pattern => replacement that matches $block, once. Returns the block and whether one matched.
     *
     * @param  array<string, string>  $edits
     * @return array{string, bool}
     */
    private static function addToFirst(string $block, array $edits): array
    {
        foreach ($edits as $pattern => $replacement) {
            $count = 0;
            $edited = (string) preg_replace($pattern, $replacement, $block, 1, $count);

            if ($count === 1) {
                return [$edited, true];
            }
        }

        return [$block, false];
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
