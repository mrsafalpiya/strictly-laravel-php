<?php

namespace StrictlyLaravel\Install;

use RuntimeException;

/**
 * Patches the TypeScript strictness flags into the app's tsconfig.json, leaving the rest of the Base Kit's config as it
 * is. tsconfig.json is JSON with comments, so it is edited as text: a flag the Base Kit lists commented out is
 * uncommented in place (keeping its comment in the formatter's layout), a flag set to false is set to true (there are
 * no toggles, ADR-0007), and a missing flag is added at the top of compilerOptions. Every flag is valid under both the
 * kit's `tsc` and the TS 7 compiler in type-aware oxlint. `exactOptionalPropertyTypes` stays off.
 */
final class TsconfigFlags
{
    public const array FLAGS = ['strict', 'noUncheckedIndexedAccess', 'noImplicitOverride', 'noFallthroughCasesInSwitch'];

    /**
     * Returns whether the file changed.
     */
    public static function patch(string $tsconfig): bool
    {
        if (! is_file($tsconfig)) {
            throw new RuntimeException('tsconfig.json not found');
        }

        $original = (string) file_get_contents($tsconfig);
        $patched = $original;

        foreach (self::FLAGS as $flag) {
            $patched = self::enable($patched, $flag);
        }

        if ($patched === $original) {
            return false;
        }

        if (file_put_contents($tsconfig, $patched) === false) {
            throw new RuntimeException("Cannot write {$tsconfig}");
        }

        return true;
    }

    private static function enable(string $config, string $flag): string
    {
        $key = '"'.$flag.'"\s*:\s*';

        if (preg_match('~^\s*'.$key.'true\b~m', $config) === 1) {
            return $config;
        }

        $count = 0;
        $config = (string) preg_replace('~^(\s*'.$key.')false\b~m', '${1}true', $config, 1, $count);

        if ($count === 1) {
            return $config;
        }

        // The Base Kit's `// "flag": true,    /* description */` becomes `"flag": true /* description */,`.
        $config = (string) preg_replace_callback(
            '~^([ \t]*)//[ \t]*('.$key.'true)[ \t]*,?[ \t]*(/\*.*\*/)?[ \t]*$~m',
            fn (array $match): string => $match[1].$match[2].(($match[3] ?? '') === '' ? '' : ' '.$match[3]).',',
            $config,
            1,
            $count,
        );

        if ($count === 1) {
            return $config;
        }

        $config = (string) preg_replace_callback(
            '~^(\s*)"compilerOptions"\s*:\s*\{[ \t]*$~m',
            fn (array $match): string => $match[0]."\n".$match[1].$match[1]."\"{$flag}\": true,",
            $config,
            1,
            $count,
        );

        if ($count === 0) {
            throw new RuntimeException("tsconfig.json has no \"compilerOptions\" object to add \"{$flag}\": true to; add it by hand");
        }

        return $config;
    }
}
