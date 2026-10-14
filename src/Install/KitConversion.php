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
     * App-relative path => SHA-256 of every known upstream version of the file, with line endings normalised to "\n".
     * The converted version is kit/<path>. When upstream changes a file, the fresh-install harness reports it as
     * skipped along with its hash: check the conversion still applies, then add the hash here.
     */
    private const array UPSTREAM = [
        'phpstan.neon' => ['5875f383d8832310ab68c37954be018ba2d5d34152a5a7166d5d7825b1fe6dcc'],
        'app/Actions/Fortify/CreateNewUser.php' => ['caaaa98a8867c44ddaa45a1f58fd388f3b46f781d22cd9f8af67e292d3b82e60'],
        'app/Http/Controllers/Settings/ProfileController.php' => ['1155df036512d539d81dcf03a116bba07a411007d125fff060c60bde41101152'],
        'app/Http/Controllers/Settings/SecurityController.php' => ['11d528a214cba42084a3eddf1d0d85608ef2bd7d3fe12fb2c99dd30e0946a78a'],
        'app/Http/Requests/Settings/ProfileUpdateRequest.php' => ['846e47b3ad44f7d7cea00c6c6b5744552cb894e82e8f5ed5a9a7244a5c8b2c6c'],
        'app/Providers/FortifyServiceProvider.php' => ['635a2513215ec6c0e7520eb1da31ab8a246db9adb0913199940475d9a9ec32ee'],
        'routes/settings.php' => ['f2b35475c66dc2f48d5234536d8584c4cc9f1e5a907b566689b5fec8a25f5a4e'],
        'routes/web.php' => ['6742122385aed4e9eb436a43420912fc97c2a3893b13f4fa3439c7f000fb1569'],
        'tests/Feature/Auth/EmailVerificationTest.php' => ['aea0c08a056689a27f0b3f0ad77b2380992ede1958445bb5e06743805eeca1df'],
        'tests/Feature/Auth/PasswordResetTest.php' => ['016ae31c83a157f21c6372c1391e1ac0c2d72e03e1c155b2ffc9baea18e74cca'],
        'tests/Pest.php' => ['eca105e821b77c688fd3c414708e48acb6d683cb94d2091cbb8493b358c4267c'],
        'tests/Unit/ExampleTest.php' => ['d86e67e8dbb5e66fa3f63b8eaaf98529bf11786e3345e76dc2e1e1635e20ea56'],
    ];

    /**
     * Convert every known file of the app at $basePath.
     *
     * @return array<string, ConversionOutcome> app-relative path => what happened to it
     */
    public static function apply(string $basePath): array
    {
        $outcomes = [];

        foreach (self::UPSTREAM as $path => $upstreamHashes) {
            $outcomes[$path] = self::convert($basePath.'/'.$path, self::CONVERTED_DIR.'/'.$path, $upstreamHashes);
        }

        return $outcomes;
    }

    /** The SHA-256 Kit Conversion compares, which ignores the difference between "\r\n" and "\n" line endings. */
    public static function hash(string $file): string
    {
        return hash('sha256', str_replace("\r\n", "\n", self::read($file)));
    }

    /**
     * @param  list<string>  $upstreamHashes
     */
    private static function convert(string $target, string $converted, array $upstreamHashes): ConversionOutcome
    {
        if (! is_file($target)) {
            return ConversionOutcome::Missing;
        }

        $hash = self::hash($target);

        if ($hash === self::hash($converted)) {
            return ConversionOutcome::AlreadyConverted;
        }

        if (! in_array($hash, $upstreamHashes, true)) {
            return ConversionOutcome::Unknown;
        }

        if (file_put_contents($target, self::read($converted)) === false) {
            throw new RuntimeException("Cannot write {$target}");
        }

        return ConversionOutcome::Converted;
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
