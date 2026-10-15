<?php

namespace StrictlyLaravel\Install;

use RuntimeException;

/**
 * Edits the Base Kit's Vite+ lint config (the `lint` object in vite.config.ts), only within the `lint: { … }` object
 * (ViteConfig), in two hooks: register() adds the npm package's oxlint plugin to `jsPlugins` and turns each of its rules
 * on as an error, and rejectAssertions() turns on oxlint's own rules against type and non-null assertions. Each adds any
 * missing rule to `rules`, creating either key when the app has none, and leaves a rule the app already configures
 * alone. Rules are never turned off (ADR-0007): an app suppresses one with a reasoned comment instead.
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
     * oxlint's own rules that reject type assertions (`x as T`, `<T>x`; `as const` stays allowed) and non-null assertions
     * (`x!`) in every linted file (P-068), with their configuration as written into the lint config: one array item per
     * line, as oxfmt formats it at the Base Kit's print width. The vendored shadcn/ui components under
     * `resources/js/components/ui/`, which the Base Kit's `ignorePatterns` leave out of lint, keep their
     * `as React.CSSProperties`; the Overlay's own casts are in the npm package (P-069).
     */
    public const array ASSERTION_RULES = [
        'typescript/consistent-type-assertions' => "[\n    'error',\n    { assertionStyle: 'never' },\n]",
        'typescript/no-non-null-assertion' => "'error'",
    ];

    /**
     * Registers the plugin and turns its rules on. Returns whether the file changed.
     */
    public static function register(string $viteConfig): bool
    {
        $rules = array_fill_keys(self::RULES, "'error'");

        return ViteConfig::patch($viteConfig, fn (string $original): string => self::editLintConfig(
            $original,
            "add `jsPlugins: ['".self::PLUGIN."']` and its rules to the Vite+ lint config by hand",
            function (string $block, string $indent) use ($rules): array {
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

                [$block, $addedRules] = self::addRules($block, $indent, $rules);

                return [$block, $added.$addedRules];
            },
        ));
    }

    /**
     * Turns on oxlint's rules against type and non-null assertions (ASSERTION_RULES, P-068). Returns whether the file
     * changed.
     */
    public static function rejectAssertions(string $viteConfig): bool
    {
        return ViteConfig::patch($viteConfig, fn (string $original): string => self::editLintConfig(
            $original,
            'add '.self::ruleList(self::ASSERTION_RULES).' to the Vite+ lint config\'s rules by hand',
            fn (string $block, string $indent): array => self::addRules($block, $indent, self::ASSERTION_RULES),
        ));
    }

    /**
     * Apply $edit to the body of the `lint: { … }` object. $edit gets the body and the indentation of its keys and
     * returns the edited body and any keys to insert at its start.
     *
     * @param  string  $byHand  what to do when the config has no `lint: {` object
     * @param  callable(string, string): array{string, string}  $edit
     */
    private static function editLintConfig(string $original, string $byHand, callable $edit): string
    {
        if (preg_match('/^([ \t]*)lint:\s*\{[ \t]*\n/m', $original, $lint, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException("vite.config.ts has no `lint: {` object; {$byHand}");
        }

        $start = $lint[0][1] + strlen($lint[0][0]);
        $end = ViteConfig::closing($original, $start, '{', '}', 'the `lint: {` object');
        [$block, $added] = $edit(substr($original, $start, $end - $start), $lint[1][0].'    ');

        return substr($original, 0, $start).$added.$block.substr($original, $end);
    }

    /**
     * Add each rule the lint config doesn't name yet to its `rules` object. Returns the edited body and, when it has no
     * `rules` key, the key to insert.
     *
     * @param  array<string, string>  $rules  rule => its configuration as TypeScript
     * @return array{string, string}
     */
    private static function addRules(string $block, string $indent, array $rules): array
    {
        $missing = array_filter($rules, fn (string $rule): bool => ! self::mentions($block, $rule), ARRAY_FILTER_USE_KEY);

        if ($missing === []) {
            return [$block, ''];
        }

        $ruleLines = implode('', array_map(
            fn (string $rule, string $config): string => "{$indent}    '{$rule}': ".str_replace("\n", "\n{$indent}    ", $config).",\n",
            array_keys($missing),
            $missing,
        ));
        [$block, $edited] = self::addToFirst($block, [
            '/\brules:\s*\{\s*\}/' => "rules: {\n{$ruleLines}{$indent}}",
            '/^[ \t]*rules:\s*\{[ \t]*\n/m' => '$0'.$ruleLines,
        ]);

        if ($edited) {
            return [$block, ''];
        }

        if (preg_match('/\brules\s*:/', $block) === 1) {
            throw new RuntimeException('vite.config.ts writes lint.rules on one line; add '.self::ruleList($missing).' to it by hand');
        }

        return [$block, "{$indent}rules: {\n{$ruleLines}{$indent}},\n"];
    }

    /**
     * The rules as one line each, for a message.
     *
     * @param  array<string, string>  $rules
     */
    private static function ruleList(array $rules): string
    {
        return implode(', ', array_map(
            fn (string $rule, string $config): string => "'{$rule}': ".preg_replace(['/\[\s+/', '/,\s*\]/', '/,\s+/'], ['[', ']', ', '], $config),
            array_keys($rules),
            $rules,
        ));
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
