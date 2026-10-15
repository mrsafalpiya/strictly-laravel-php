<?php

namespace StrictlyLaravel\Install;

use RuntimeException;

/**
 * Registers the npm package's oxlint plugin in the Base Kit's Vite+ lint config (the `lint` object in vite.config.ts)
 * and turns each of its rules on as an error: adds the plugin to `jsPlugins` and any missing rule to `rules`, creating
 * either key when the app has none, editing only within the `lint: { … }` object (ViteConfig). Rules are never turned
 * off (ADR-0007): an app suppresses one with a reasoned comment instead.
 */
final class ViteLintPlugin
{
    public const string PLUGIN = '@mrsafalpiya/strictly-laravel/oxlint';

    /** The plugin's rules, all on as errors. */
    public const array RULES = [
        'strictly/no-hardcoded-urls',
        'strictly/require-suppression-reason',
        'strictly/typed-broadcasts',
        'strictly/typed-form-fields',
        'strictly/typed-forms',
        'strictly/typed-inertia-components',
        'strictly/typed-optimistic-updates',
        'strictly/typed-page-props',
        'strictly/typed-prop-names',
        'strictly/typed-requests',
    ];

    /**
     * Returns whether the file changed.
     */
    public static function register(string $viteConfig): bool
    {
        return ViteConfig::patch($viteConfig, self::addToLintConfig(...));
    }

    private static function addToLintConfig(string $original): string
    {
        if (preg_match('/^([ \t]*)lint:\s*\{[ \t]*\n/m', $original, $lint, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException('vite.config.ts has no `lint: {` object; add `jsPlugins: [\''.self::PLUGIN.'\']` and its rules to the Vite+ lint config by hand');
        }

        $start = $lint[0][1] + strlen($lint[0][0]);
        $end = ViteConfig::closing($original, $start, '{', '}', 'the `lint: {` object');
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

        return substr($original, 0, $start).$added.$block.substr($original, $end);
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
}
