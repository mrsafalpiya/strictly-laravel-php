<?php

namespace StrictlyLaravel\Install;

/**
 * The messages an `ignoreErrors` entry's `message` (a regex) or `rawMessage` ignores, read as text with gaps: a literal
 * character each, and a gap for whatever a wildcard, a character class, a quantified or alternating group or an
 * unanchored end can match. It ignores a rule's message when the two can be the same text and the entry's literal text
 * pins at least PINNED characters of the template's, a run of them in a row among them, so that a regex for some other
 * error (whose class names fit a template's `%s`) doesn't count, and neither does a catch-all such as `#.*#` (P-200).
 */
final class IgnoredMessage
{
    /** How many of a template's literal characters the entry must match to ignore it. */
    private const int PINNED = 12;

    /**
     * @param  list<list<string|null>>  $alternatives  each a character, or null for a gap
     */
    private function __construct(private readonly array $alternatives, private readonly bool $caseless) {}

    /** A `rawMessage`, which PHPStan compares with the whole message. */
    public static function raw(string $message): self
    {
        return new self([str_split($message)], false);
    }

    /** A `message`, a regex PHPStan matches against the message, such as `#^Data class .+ must be final\.$#`. */
    public static function regex(string $regex): self
    {
        $delimiter = $regex[0] ?? '';
        $closing = strrpos($regex, ['(' => ')', '{' => '}', '[' => ']', '<' => '>'][$delimiter] ?? $delimiter);

        if ($closing === false || $closing === 0) {
            return new self([[null]], false);
        }

        $body = substr($regex, 1, $closing - 1);
        $at = 0;
        $alternatives = [];

        foreach (self::alternation($body, $at, true) as [$tokens, $start, $end]) {
            $alternatives[] = self::gapsMerged([...($start ? [] : [null]), ...$tokens, ...($end ? [] : [null])]);
        }

        return new self($alternatives, str_contains(substr($regex, $closing + 1), 'i'));
    }

    /** Whether some message this entry ignores fits $template, a message with `%s` for each part that varies. */
    public function ignores(string $template): bool
    {
        $template = $this->caseless ? strtolower($template) : $template;
        $text = str_replace('%s', "\0", $template);
        $tokens = [];

        foreach (explode('%s', $template) as $i => $part) {
            array_push($tokens, ...($i === 0 ? [] : [null]), ...str_split($part));
        }

        foreach ($this->alternatives as $alternative) {
            if ($this->caseless) {
                $alternative = array_map(fn (?string $token): ?string => $token === null ? null : strtolower($token), $alternative);
            }

            if (self::sharesRun($alternative, $text) && self::pinned($alternative, $tokens) >= self::PINNED) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether PINNED literal characters in a row of $tokens appear in $text: a quick test that rules out most templates
     * before pinned() compares the two in full.
     *
     * @param  list<string|null>  $tokens
     */
    private static function sharesRun(array $tokens, string $text): bool
    {
        foreach (explode("\0", implode('', array_map(fn (?string $token): string => $token ?? "\0", $tokens))) as $literal) {
            for ($i = 0; $i + self::PINNED <= strlen($literal); $i++) {
                if (str_contains($text, substr($literal, $i, self::PINNED))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The most literal characters of $a matched by literal characters of $b over any text both can be, or -1 when there
     * is none. A gap in either takes any run of the other's tokens.
     *
     * @param  list<string|null>  $a
     * @param  list<string|null>  $b
     */
    private static function pinned(array $a, array $b): int
    {
        $n = count($a);
        $m = count($b);
        $next = array_fill(0, $m + 1, -1);

        for ($i = $n; $i >= 0; $i--) {
            $row = array_fill(0, $m + 1, -1);
            $token = $i < $n ? $a[$i] : false;

            for ($j = $m; $j >= 0; $j--) {
                $other = $j < $m ? $b[$j] : false;
                $best = $token === false && $other === false ? 0 : -1;

                if ($token === null) {
                    // $a's gap ends here, or takes $b's next token.
                    $best = $other !== false && $row[$j + 1] > $next[$j] ? $row[$j + 1] : $next[$j];
                }

                if ($other === null) {
                    // $b's gap ends here, or takes $a's next token.
                    $best = max($best, $row[$j + 1], $token === false ? -1 : $next[$j]);
                } elseif (is_string($token) && $token === $other && $next[$j + 1] >= 0) {
                    $best = max($best, $next[$j + 1] + 1);
                }

                $row[$j] = $best;
            }

            $next = $row;
        }

        return $next[0];
    }

    /**
     * Read alternatives separated by `|` from $at up to a `)` (or the end at the top level), each as its tokens and,
     * at the top level, whether it is anchored at its start and end.
     *
     * @return list<array{list<string|null>, bool, bool}>
     */
    private static function alternation(string $body, int &$at, bool $top): array
    {
        $alternatives = [];
        $atoms = [];
        $start = false;
        $end = false;

        while ($at < strlen($body) && $body[$at] !== ')') {
            $char = $body[$at++];

            if ($char === '|') {
                $alternatives[] = [array_merge(...$atoms), $start, $end];
                [$atoms, $start, $end] = [[], false, false];

                continue;
            }

            if ($char === '^' || $char === '$') {
                $start = $start || ($top && $char === '^' && $atoms === []);
                $end = $top && $char === '$';

                continue;
            }

            $atom = match ($char) {
                '\\' => self::escape($body, $at),
                '.' => [null],
                '[' => self::characterClass($body, $at),
                '(' => self::group($body, $at),
                default => [$char],
            };

            if (preg_match('/\G(?:[*+?]|\{\d*,?\d*\})[?+]?/', $body, $quantifier, 0, $at) === 1) {
                $at += strlen($quantifier[0]);
                $atom = $atom === [] ? [] : [null];
            }

            $atoms[] = $atom;
            $end = false;
        }

        $alternatives[] = [array_merge(...$atoms), $start, $end];

        return $alternatives;
    }

    /**
     * The tokens of a group whose `(` is before $at, up to and past its `)`: its own tokens when it has one alternative,
     * none for a lookaround or an inline option such as `(?i)`, a gap otherwise.
     *
     * @return list<string|null>
     */
    private static function group(string $body, int &$at): array
    {
        $lookaround = preg_match('/\G\?(?:<?[=!]|[a-zA-Z-]*\))/', $body, $prefix, 0, $at) === 1;
        $at += preg_match('/\G\?(?:<[a-zA-Z_]\w*>|P<[a-zA-Z_]\w*>|\'[a-zA-Z_]\w*\'|<?[=!]|[a-zA-Z-]*:|[a-zA-Z-]*(?=\)))/', $body, $skipped, 0, $at) === 1
            ? strlen($skipped[0])
            : 0;
        $alternatives = self::alternation($body, $at, false);
        $at++;

        return match (true) {
            $lookaround => [],
            count($alternatives) === 1 => $alternatives[0][0],
            default => [null],
        };
    }

    /**
     * The tokens of an escape whose `\` is before $at: the character itself, a quoted `\Q…\E` run, nothing for an
     * assertion such as `\b`, a gap for a class such as `\d` or a back-reference.
     *
     * @return list<string|null>
     */
    private static function escape(string $body, int &$at): array
    {
        $char = $body[$at++] ?? '\\';

        if ($char === 'Q') {
            $close = strpos($body, '\\E', $at);
            $quoted = substr($body, $at, $close === false ? null : $close - $at);
            $at = $close === false ? strlen($body) : $close + 2;

            return str_split($quoted);
        }

        if (($char === 'p' || $char === 'P') && preg_match('/\G(?:\{[^}]*\}|\w)/', $body, $property, 0, $at) === 1) {
            $at += strlen($property[0]);
        }

        return match (true) {
            str_contains('bBAzZGK', $char) => [],
            str_contains('dDwWsShHvVRNXpPC', $char), ctype_digit($char) => [null],
            $char === 'n' => ["\n"],
            $char === 't' => ["\t"],
            default => [$char],
        };
    }

    /**
     * A gap for a character class whose `[` is before $at, skipping past its `]`.
     *
     * @return list<string|null>
     */
    private static function characterClass(string $body, int &$at): array
    {
        $at += preg_match('/\G\^?\]?(?:\\\\.|\[:\w+:\]|[^\]\\\\])*\]?/s', $body, $class, 0, $at) === 1 ? strlen($class[0]) : 0;

        return [null];
    }

    /**
     * @param  list<string|null>  $tokens
     * @return list<string|null>
     */
    private static function gapsMerged(array $tokens): array
    {
        return array_values(array_filter($tokens, fn (?string $token, int $i): bool => $token !== null || $i === 0 || $tokens[$i - 1] !== null, ARRAY_FILTER_USE_BOTH));
    }
}
