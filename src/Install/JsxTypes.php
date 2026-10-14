<?php

namespace StrictlyLaravel\Install;

use RuntimeException;

/**
 * The narrowed JSX types (ADR-0004, P-015): points tsconfig.json's `jsxImportSource` at the npm package's JSX types,
 * whose `name` on `<input>`, `<select>`, `<textarea>` and `<button>` accepts only a `FieldName`, and pins the Vite React
 * plugin back to React's own runtime (`react({ jsxImportSource: 'react' })`), because the plugin otherwise follows
 * tsconfig.json and imports a JSX runtime that has types only (P-016). This is the one Base Kit toolchain setting the
 * Overlay overrides. Both files are edited as text, in the Base Kit's formatting.
 */
final class JsxTypes
{
    public const string SOURCE = '@mrsafalpiya/strictly-laravel/jsx';

    private const string REACT_PLUGIN = '@vitejs/plugin-react';

    private const string PIN = "jsxImportSource: 'react'";

    /**
     * Sets `"jsxImportSource"` in tsconfig.json. Returns whether the file changed.
     */
    public static function patchTsconfig(string $tsconfig): bool
    {
        if (! is_file($tsconfig)) {
            throw new RuntimeException('tsconfig.json not found');
        }

        $original = (string) file_get_contents($tsconfig);
        $patched = self::setImportSource($original);

        if ($patched === $original) {
            return false;
        }

        if (file_put_contents($tsconfig, $patched) === false) {
            throw new RuntimeException("Cannot write {$tsconfig}");
        }

        return true;
    }

    /**
     * Pins the Vite React plugin's JSX runtime to React in vite.config.ts. Returns whether the file changed.
     */
    public static function pinViteReactRuntime(string $viteConfig): bool
    {
        return ViteConfig::patch($viteConfig, self::pin(...));
    }

    private static function setImportSource(string $config): string
    {
        $key = '"jsxImportSource"\s*:\s*';

        if (preg_match('~^\s*'.$key.'"([^"]*)"~m', $config, $current) === 1) {
            if ($current[1] === self::SOURCE) {
                return $config;
            }

            if (! in_array($current[1], ['', 'react'], true)) {
                throw new RuntimeException("tsconfig.json sets \"jsxImportSource\" to \"{$current[1]}\", but the narrowed JSX types are React's (ADR-0004); set it to \"".self::SOURCE.'" by hand if the app can use React\'s JSX');
            }

            return (string) preg_replace('~^(\s*'.$key.')"[^"]*"~m', '${1}"'.self::SOURCE.'"', $config, 1);
        }

        $count = 0;
        // The Base Kit's `// "jsxImportSource": "",    /* description */` becomes `"jsxImportSource": "…" /* description */,`.
        $config = (string) preg_replace_callback(
            '~^([ \t]*)//[ \t]*('.$key.')"[^"]*"[ \t]*,?[ \t]*(/\*.*\*/)?[ \t]*$~m',
            fn (array $match): string => $match[1].$match[2].'"'.self::SOURCE.'"'.(($match[3] ?? '') === '' ? '' : ' '.$match[3]).',',
            $config,
            1,
            $count,
        );

        if ($count === 1) {
            return $config;
        }

        $config = (string) preg_replace_callback(
            '~^(\s*)"compilerOptions"\s*:\s*\{[ \t]*$~m',
            fn (array $match): string => $match[0]."\n".$match[1].$match[1].'"jsxImportSource": "'.self::SOURCE.'",',
            $config,
            1,
            $count,
        );

        if ($count === 0) {
            throw new RuntimeException('tsconfig.json has no "compilerOptions" object to add "jsxImportSource": "'.self::SOURCE.'" to; add it by hand');
        }

        return $config;
    }

    private static function pin(string $source): string
    {
        $manually = 'pass `'.self::PIN.'` to the Vite React plugin by hand';

        if (preg_match('/^import\s+(\w+)\b[^;]*from\s+[\'"]'.preg_quote(self::REACT_PLUGIN, '/').'[\'"]/m', $source, $import) !== 1) {
            throw new RuntimeException("vite.config.ts doesn't import the Vite React plugin as a default import from '".self::REACT_PLUGIN."'; {$manually}");
        }

        $call = '/\b'.preg_quote($import[1], '/').'\(\s*/';

        if (preg_match($call, $source, $found, PREG_OFFSET_CAPTURE) !== 1) {
            throw new RuntimeException("vite.config.ts doesn't call the Vite React plugin `{$import[1]}()`; {$manually}");
        }

        $argumentsStart = $found[0][1] + strlen($found[0][0]);
        $close = ViteConfig::closing($source, $found[0][1] + strlen($import[1]) + 1, '(', ')', "the Vite React plugin's options");
        $arguments = substr($source, $argumentsStart, $close - $argumentsStart);

        if (preg_match('/\bjsxImportSource\s*:\s*([\'"])react\1/', $arguments) === 1) {
            return $source;
        }

        if (preg_match('/\bjsxImportSource\s*:/', $arguments) === 1) {
            throw new RuntimeException("vite.config.ts gives the Vite React plugin another `jsxImportSource`; the narrowed JSX types are React's (ADR-0004), so {$manually}");
        }

        if (trim($arguments) === '') {
            return substr($source, 0, $argumentsStart).'{ '.self::PIN.' }'.substr($source, $close);
        }

        if (preg_match('/^\{([ \t]*\n([ \t]*))?/', $arguments, $object) !== 1) {
            throw new RuntimeException("vite.config.ts passes the Vite React plugin options that aren't an object literal; {$manually}");
        }

        $insert = isset($object[2]) ? self::PIN.",\n".$object[2] : ' '.self::PIN.',';

        return substr($source, 0, $argumentsStart + strlen($object[0])).$insert.substr($source, $argumentsStart + strlen($object[0]));
    }
}
