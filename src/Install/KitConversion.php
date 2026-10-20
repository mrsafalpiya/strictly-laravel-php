<?php

namespace StrictlyLaravel\Install;

use RuntimeException;

/**
 * Kit Conversion (ADR-0005): replaces the Base Kit's own files with the versions under kit/, which follow Strictly
 * Laravel's conventions, but only a file whose content is a known upstream version. Any other content is the app's,
 * so the file is left untouched and reported; a file already converted is left alone. A conversion can also add new
 * files (the Page classes a converted controller returns): they are written together with the file that needs them,
 * and only then, so a later run never recreates one the app has since deleted.
 *
 * The starter kit cuts the auth features an app didn't keep out of its files (KitFeatures, Problem P-211), so both
 * sides depend on the app's features: a file's known upstream versions are listed per combination of the features it
 * varies with, and every file under kit/ is a KitTemplate, written as its version for the app's features.
 *
 * @phpstan-type ManifestEntry array{
 *     upstream: list<string>|array<string, list<string>>,
 *     variesWith?: list<string>,
 *     requires?: string,
 *     earlierConversions: list<string>,
 *     adds?: list<string>,
 *     addsWith?: array<string, list<string>>,
 * }
 */
final class KitConversion
{
    private const string CONVERTED_DIR = __DIR__.'/../../kit';

    /**
     * App-relative path => entry:
     *
     * - "upstream": the SHA-256 (line endings normalised to "\n") of every known upstream version of the file, which is
     *   replaced with kit/<path>. For a file that differs with the app's features, those listed in "variesWith", it is
     *   an object keyed by the features kept among them (KitFeatures::key(): "registration,passkeys", or "none").
     * - "requires" (optional): the feature without which the Base Kit has no such file, so it is not expected.
     * - "earlierConversions": the hash of every converted version an earlier release wrote, which is left alone like
     *   the current one.
     * - "adds" (optional): the files under kit/ that the converted version needs and the Base Kit doesn't have, written
     *   when the file is converted; "addsWith" (optional), feature => more of them, written only for an app with it.
     *
     * When upstream changes a file, the fresh-install harness reports it as skipped and the installer's warning gives
     * its hash: check the conversion still applies, then add the hash to "upstream". The harness fails until a changed
     * kit/ file's previous versions are listed in "earlierConversions", and until every version of a feature
     * combination is listed in "upstream".
     */
    private const string MANIFEST = __DIR__.'/../../kit-conversion.json';

    /**
     * Convert every known file of the app at $basePath, to its version for the app's starter kit features. A file the
     * Base Kit has only with a feature the app didn't keep gets no outcome.
     *
     * @return array<string, ConversionOutcome> app-relative path => what happened to it
     */
    public static function apply(string $basePath): array
    {
        $features = KitFeatures::detect($basePath);
        $outcomes = [];

        foreach (self::manifest() as $path => $entry) {
            if (isset($entry['requires']) && ! in_array($entry['requires'], $features, true)) {
                continue;
            }

            $outcomes[$path] = self::convert($basePath.'/'.$path, $path, self::upstream($entry, $features), $entry['earlierConversions'], $features);

            if ($outcomes[$path] === ConversionOutcome::Converted) {
                foreach (self::adds($entry, $features) as $added) {
                    $outcomes[$added] = self::add($basePath.'/'.$added, $added, $features);
                }
            }
        }

        return $outcomes;
    }

    /**
     * The files the conversion of $path adds to the app at $basePath, which an app that skipped it needs to create by
     * hand.
     *
     * @return list<string>
     */
    public static function addedBy(string $path, string $basePath): array
    {
        $entry = self::manifest()[$path] ?? null;

        return $entry === null ? [] : self::adds($entry, KitFeatures::detect($basePath));
    }

    /** The SHA-256 Kit Conversion compares, which ignores the difference between "\r\n" and "\n" line endings. */
    public static function hash(string $file): string
    {
        return self::hashContent(self::read($file));
    }

    /**
     * @param  list<string>  $upstream
     * @param  list<string>  $earlierConversions
     * @param  list<string>  $features
     */
    private static function convert(string $target, string $path, array $upstream, array $earlierConversions, array $features): ConversionOutcome
    {
        if (! is_file($target)) {
            return ConversionOutcome::Missing;
        }

        $hash = self::hash($target);
        $template = self::read(self::CONVERTED_DIR.'/'.$path);

        if (self::isConverted($hash, $template) || in_array($hash, $earlierConversions, true)) {
            return ConversionOutcome::AlreadyConverted;
        }

        if (! in_array($hash, $upstream, true)) {
            return ConversionOutcome::Unknown;
        }

        self::write($target, KitTemplate::render($template, $features));

        return ConversionOutcome::Converted;
    }

    /**
     * Write an added file, unless the app already has a file at its path with other content.
     *
     * @param  list<string>  $features
     */
    private static function add(string $target, string $path, array $features): ConversionOutcome
    {
        $template = self::read(self::CONVERTED_DIR.'/'.$path);

        if (is_file($target)) {
            return self::isConverted(self::hash($target), $template) ? ConversionOutcome::AlreadyConverted : ConversionOutcome::Unknown;
        }

        if (! is_dir(dirname($target)) && ! mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
            throw new RuntimeException('Cannot create '.dirname($target));
        }

        self::write($target, KitTemplate::render($template, $features));

        return ConversionOutcome::Converted;
    }

    /** Whether $hash is the converted version of a file for some combination of features. */
    private static function isConverted(string $hash, string $template): bool
    {
        foreach (KitTemplate::renderings($template) as $rendering) {
            if (self::hashContent($rendering) === $hash) {
                return true;
            }
        }

        return false;
    }

    /**
     * The known upstream versions of an entry's file for an app with $features.
     *
     * @param  ManifestEntry  $entry
     * @param  list<string>  $features
     * @return list<string>
     */
    private static function upstream(array $entry, array $features): array
    {
        $known = isset($entry['variesWith'])
            ? $entry['upstream'][KitFeatures::key(array_values(array_intersect($features, $entry['variesWith'])))] ?? []
            : $entry['upstream'];

        return is_array($known) ? array_values(array_filter($known, is_string(...))) : [];
    }

    /**
     * The files an entry's conversion adds to an app with $features.
     *
     * @param  ManifestEntry  $entry
     * @param  list<string>  $features
     * @return list<string>
     */
    private static function adds(array $entry, array $features): array
    {
        $adds = $entry['adds'] ?? [];

        foreach ($entry['addsWith'] ?? [] as $feature => $files) {
            if (in_array($feature, $features, true)) {
                $adds = [...$adds, ...$files];
            }
        }

        return $adds;
    }

    /**
     * @return array<string, ManifestEntry>
     */
    private static function manifest(): array
    {
        /** @var array<string, ManifestEntry> */
        return json_decode(self::read(self::MANIFEST), true, flags: JSON_THROW_ON_ERROR);
    }

    private static function hashContent(string $content): string
    {
        return hash('sha256', str_replace("\r\n", "\n", $content));
    }

    private static function write(string $file, string $content): void
    {
        if (file_put_contents($file, $content) === false) {
            throw new RuntimeException("Cannot write {$file}");
        }
    }

    private static function read(string $file): string
    {
        $content = file_get_contents($file);

        if ($content === false) {
            throw new RuntimeException("Cannot read {$file}");
        }

        return $content;
    }
}
