<?php

namespace StrictlyLaravel\Install;

use RuntimeException;

/**
 * A file under kit/ (or a stub) whose lines can depend on the app's starter kit features (KitFeatures, Problem P-211).
 * A section is the lines between a marker line naming its condition and an end marker line, each alone on its line and
 * written in whichever comment the file's syntax allows. In PHP and TypeScript:
 *
 * `// @feature registration` … `// @end-feature`
 *
 * Inside JSX:
 *
 * `{/* @feature passkeys *\/}` … `{/* @end-feature *\/}`
 *
 * A section's lines are kept when its condition holds and dropped otherwise, and the marker lines are always dropped,
 * so with every feature kept the file reads as it did before it had sections. A condition is one or more features
 * joined by `|`, any of which must be kept, each optionally negated with `!` (`2fa|passkeys`, `!registration`).
 * Sections nest.
 */
final class KitTemplate
{
    private const string MARKER = '~^\h*(?://|\{?/\*)\h*@(end-)?feature\b\h*([^*\h]*)\h*(?:\*/\}?)?\h*$~';

    /**
     * The file for an app with $features.
     *
     * @param  list<string>  $features
     */
    public static function render(string $content, array $features): string
    {
        $lines = explode("\n", $content);
        $kept = [];
        /** @var list<bool> $open whether each enclosing section holds */
        $open = [];

        foreach ($lines as $number => $line) {
            if (preg_match(self::MARKER, $line, $marker) !== 1) {
                if (! in_array(false, $open, true)) {
                    $kept[] = $line;
                }

                continue;
            }

            if ($marker[1] === 'end-') {
                if ($open === []) {
                    throw new RuntimeException('An @end-feature on line '.($number + 1).' closes no section.');
                }

                array_pop($open);

                continue;
            }

            $open[] = self::holds($marker[2], $features, $number + 1);
        }

        if ($open !== []) {
            throw new RuntimeException('A @feature section is never closed with @end-feature.');
        }

        return implode("\n", $kept);
    }

    /**
     * The features the file's sections depend on, in the order of KitFeatures::ALL.
     *
     * @return list<string>
     */
    public static function features(string $content): array
    {
        $features = [];

        foreach (explode("\n", $content) as $line) {
            if (preg_match(self::MARKER, $line, $marker) === 1 && $marker[1] === '') {
                foreach (explode('|', $marker[2]) as $term) {
                    $features[] = ltrim($term, '!');
                }
            }
        }

        return array_values(array_intersect(KitFeatures::ALL, $features));
    }

    /**
     * The file for every combination of the features it depends on.
     *
     * @return array<string, string> KitFeatures::key() of the combination => the file for it
     */
    public static function renderings(string $content): array
    {
        $renderings = [];

        foreach (KitFeatures::subsets(self::features($content)) as $features) {
            $renderings[KitFeatures::key($features)] = self::render($content, $features);
        }

        return $renderings;
    }

    /** @param  list<string>  $features */
    private static function holds(string $condition, array $features, int $line): bool
    {
        if ($condition === '') {
            throw new RuntimeException("The @feature marker on line {$line} names no feature.");
        }

        foreach (explode('|', $condition) as $term) {
            $feature = ltrim($term, '!');

            if (! in_array($feature, KitFeatures::ALL, true)) {
                throw new RuntimeException("The @feature marker on line {$line} names an unknown feature, \"{$feature}\" (known: ".implode(', ', KitFeatures::ALL).').');
            }

            if (in_array($feature, $features, true) !== str_starts_with($term, '!')) {
                return true;
            }
        }

        return false;
    }
}
