<?php

namespace StrictlyLaravel\Install;

use RuntimeException;

/**
 * Kit Conversion (ADR-0005): replaces the Base Kit's own files with the versions under kit/, which follow Strictly
 * Laravel's conventions, but only a file whose content is a known upstream version. Any other content is the app's,
 * so the file is left untouched and reported; a file already converted is left alone.
 */
final class KitConversion
{
    private const string CONVERTED_DIR = __DIR__.'/../../kit';

    /**
     * App-relative path => {upstream, earlierConversions}: the SHA-256 (line endings normalised to "\n") of every known
     * upstream version of the file, which is replaced with kit/<path>, and of every converted version an earlier
     * release wrote, which is left alone like the current one. When upstream changes a file, the fresh-install
     * harness reports it as skipped and the installer's warning gives its hash: check the conversion still applies,
     * then add the hash to "upstream". The harness fails until a changed kit/ file's previous version is listed in
     * "earlierConversions".
     */
    private const string MANIFEST = __DIR__.'/../../kit-conversion.json';

    /**
     * Convert every known file of the app at $basePath.
     *
     * @return array<string, ConversionOutcome> app-relative path => what happened to it
     */
    public static function apply(string $basePath): array
    {
        $outcomes = [];

        foreach (self::manifest() as $path => ['upstream' => $upstream, 'earlierConversions' => $earlierConversions]) {
            $outcomes[$path] = self::convert($basePath.'/'.$path, self::CONVERTED_DIR.'/'.$path, $upstream, $earlierConversions);
        }

        return $outcomes;
    }

    /** The SHA-256 Kit Conversion compares, which ignores the difference between "\r\n" and "\n" line endings. */
    public static function hash(string $file): string
    {
        return hash('sha256', str_replace("\r\n", "\n", self::read($file)));
    }

    /**
     * @param  list<string>  $upstream
     * @param  list<string>  $earlierConversions
     */
    private static function convert(string $target, string $converted, array $upstream, array $earlierConversions): ConversionOutcome
    {
        if (! is_file($target)) {
            return ConversionOutcome::Missing;
        }

        $hash = self::hash($target);

        if ($hash === self::hash($converted) || in_array($hash, $earlierConversions, true)) {
            return ConversionOutcome::AlreadyConverted;
        }

        if (! in_array($hash, $upstream, true)) {
            return ConversionOutcome::Unknown;
        }

        if (file_put_contents($target, self::read($converted)) === false) {
            throw new RuntimeException("Cannot write {$target}");
        }

        return ConversionOutcome::Converted;
    }

    /**
     * @return array<string, array{upstream: list<string>, earlierConversions: list<string>}>
     */
    private static function manifest(): array
    {
        /** @var array<string, array{upstream: list<string>, earlierConversions: list<string>}> */
        return json_decode(self::read(self::MANIFEST), true, flags: JSON_THROW_ON_ERROR);
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
