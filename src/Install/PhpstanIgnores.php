<?php

namespace StrictlyLaravel\Install;

use Nette\Neon\Exception as NeonException;
use Nette\Neon\Neon;
use RuntimeException;
use StrictlyLaravel\PHPStan\RuleMessages;

/**
 * Checks that phpstan.neon, and every file of the app it includes (a baseline among them), ignores no error of a
 * Strictly Laravel rule in `ignoreErrors`, whether by identifier or by message, in every file or only some (P-190):
 * the Overlay has no toggles, only a suppression on the line with a reason (ADR-0007). Changes nothing; throws when it
 * finds one.
 */
final class PhpstanIgnores
{
    /** Returns false: it never changes the app. */
    public static function reject(string $phpstanNeon): false
    {
        if (! is_file($phpstanNeon)) {
            return false;
        }

        $root = (string) realpath(dirname($phpstanNeon));
        $errors = [];

        foreach (self::configs((string) realpath($phpstanNeon), $root) as $file => $config) {
            $parameters = is_array($config['parameters'] ?? null) ? $config['parameters'] : [];

            foreach ([...self::listed($parameters['ignoreErrors'] ?? []), ...self::listed($parameters['ignoreErrors!'] ?? [])] as $entry) {
                $error = self::ignored(is_array($entry) ? $entry : ['message' => $entry]);

                if ($error !== null) {
                    $errors[] = substr($file, strlen($root) + 1).' '.$error;
                }
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(implode(' ', $errors));
        }

        return false;
    }

    /**
     * What the entry ignores of a Strictly Laravel rule, as the end of the message, or null when it ignores no rule's
     * errors. PHPStan ignores an error only when it matches every condition the entry gives: an entry that names other
     * identifiers ignores no rule's, whatever its message.
     *
     * @param  array<mixed>  $entry
     */
    private static function ignored(array $entry): ?string
    {
        $identifiers = self::strings($entry, 'identifier', 'identifiers');
        $byMessage = $identifiers === [];
        $rules = array_values(array_filter($identifiers, fn (string $identifier): bool => str_starts_with($identifier, 'strictly.')));

        if ($byMessage) {
            $messages = [
                ...array_map(IgnoredMessage::regex(...), self::strings($entry, 'message', 'messages')),
                ...array_map(IgnoredMessage::raw(...), self::strings($entry, 'rawMessage', 'rawMessages')),
            ];
            $rules = array_keys(array_filter(
                RuleMessages::TEMPLATES,
                fn (array $templates): bool => array_any($messages, fn (IgnoredMessage $message): bool => array_any($templates, $message->ignores(...))),
            ));
        }

        if ($rules === []) {
            return null;
        }

        $paths = self::strings($entry, 'path', 'paths');
        $named = array_map(fn (string $rule): string => "`{$rule}`", $rules);
        $last = array_pop($named);

        return 'ignores '.($named === [] ? '' : implode(', ', $named).' and ').$last.' errors'
            .($byMessage ? ' by their message' : '')
            .($paths === [] ? '' : ' in '.implode(', ', $paths))
            .'; '.NoToggles::message('the entry', '// @phpstan-ignore '.(count($rules) === 1 ? $rules[0] : 'strictly.<rule>').' (reason)');
    }

    /**
     * $file's config, then the config of every file of the app it includes, recursively, by path. Vendor files and
     * paths with a parameter (`%rootDir%/…`) aren't the app's.
     *
     * @param  array<string, true>  $seen
     * @return array<string, array<mixed>>
     */
    private static function configs(string $file, string $root, array &$seen = []): array
    {
        $seen[$file] = true;

        try {
            $config = Neon::decodeFile($file);
        } catch (NeonException $e) {
            throw new RuntimeException(substr($file, strlen($root) + 1)." can't be read: {$e->getMessage()}");
        }

        $config = is_array($config) ? $config : [];
        $configs = [$file => $config];

        foreach (self::listed($config['includes'] ?? []) as $include) {
            if (! is_string($include) || str_contains($include, '%') || str_contains($include, '://')) {
                continue;
            }

            $path = realpath(str_starts_with($include, '/') ? $include : dirname($file).'/'.$include);

            if ($path !== false && str_starts_with($path, "{$root}/") && ! str_starts_with($path, "{$root}/vendor/") && ! isset($seen[$path])) {
                $configs += self::configs($path, $root, $seen);
            }
        }

        return $configs;
    }

    /**
     * The strings an entry gives for a key that takes one ($one) or a list ($many).
     *
     * @param  array<mixed>  $entry
     * @return list<string>
     */
    private static function strings(array $entry, string $one, string $many): array
    {
        return array_values(array_filter([$entry[$one] ?? null, ...self::listed($entry[$many] ?? [])], is_string(...)));
    }

    /** @return list<mixed> */
    private static function listed(mixed $value): array
    {
        return is_array($value) ? array_values($value) : [];
    }
}
