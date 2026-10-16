<?php

namespace StrictlyLaravel\Install;

use JsonException;
use RuntimeException;
use stdClass;

/**
 * Edits the "scripts" object of an app's composer.json. Only that object is rewritten, so the rest of the file keeps its
 * formatting byte for byte.
 */
final class ComposerScripts
{
    /** How many brackets are open where the root object's keys sit: its own `{` alone. */
    private const int TOP_LEVEL_KEY_DEPTH = 1;

    /**
     * Replace the scripts with what $edit returns for them. Returns whether the file changed.
     *
     * @param  callable(stdClass): stdClass  $edit  receives the current scripts (an empty object when there are none)
     */
    public static function rewrite(string $composerJson, callable $edit): bool
    {
        $original = file_get_contents($composerJson);

        if ($original === false) {
            throw new RuntimeException("Cannot read {$composerJson}");
        }

        try {
            $manifest = json_decode($original, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("{$composerJson} is not valid JSON: {$e->getMessage()}", previous: $e);
        }

        if (! $manifest instanceof stdClass) {
            throw new RuntimeException("{$composerJson} is not a JSON object");
        }

        $existing = $manifest->scripts ?? new stdClass;

        if (! $existing instanceof stdClass) {
            throw new RuntimeException("The \"scripts\" of {$composerJson} are not a JSON object");
        }

        $scripts = $edit($existing);
        $span = self::topLevelObjectSpan($original, 'scripts');

        if ($span !== null) {
            [$start, $end, $indent] = $span;
            $updated = substr($original, 0, $start)
                .str_replace("\n", "\n{$indent}", self::encode($scripts))
                .substr($original, $end);
        } else {
            $manifest->scripts = $scripts;
            $updated = self::encode($manifest).(str_ends_with($original, "\n") ? "\n" : '');
        }

        if ($updated === $original) {
            return false;
        }

        file_put_contents($composerJson, $updated);

        return true;
    }

    private static function encode(stdClass $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * Where the object value of a top-level key sits in JSON text: [start, end) byte offsets and the indentation of
     * the key's line. Null when the key is absent or its value isn't an object.
     *
     * @return array{int, int, string}|null
     */
    private static function topLevelObjectSpan(string $json, string $key): ?array
    {
        $depth = 0;
        $start = null;
        $indent = '';

        for ($i = 0, $length = strlen($json); $i < $length; $i++) {
            $char = $json[$i];

            if ($char === '"') {
                $close = self::closingQuote($json, $i);

                if ($start === null && $depth === self::TOP_LEVEL_KEY_DEPTH
                    && preg_match('/\G\s*:\s*/', $json, $colon, 0, $close + 1) === 1
                    && json_decode(substr($json, $i, $close - $i + 1)) === $key) {
                    $start = $close + 1 + strlen($colon[0]);

                    if (($json[$start] ?? '') !== '{') {
                        return null;
                    }

                    $lineStart = strrpos(substr($json, 0, $i), "\n");
                    $indent = substr($json, $lineStart === false ? 0 : $lineStart + 1, $i - ($lineStart === false ? 0 : $lineStart + 1));
                }

                $i = $close;
            } elseif ($char === '{' || $char === '[') {
                $depth++;
            } elseif ($char === '}' || $char === ']') {
                $depth--;

                if ($start !== null && $depth === self::TOP_LEVEL_KEY_DEPTH) {
                    return [$start, $i + 1, trim($indent) === '' ? $indent : ''];
                }
            }
        }

        return null;
    }

    private static function closingQuote(string $json, int $open): int
    {
        for ($i = $open + 1, $length = strlen($json); $i < $length; $i++) {
            if ($json[$i] === '\\') {
                $i++;
            } elseif ($json[$i] === '"') {
                return $i;
            }
        }

        throw new RuntimeException('Unterminated string in composer.json');
    }
}
