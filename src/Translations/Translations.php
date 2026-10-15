<?php

namespace StrictlyLaravel\Translations;

use Illuminate\Support\Arr;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The app's own translations (ADR-0006): every locale's messages under lang/, flattened to the keys Laravel's `__()`
 * takes (`auth.throttle` for lang/en/auth.php, `admin/users.title` for lang/en/admin/users.php, the message itself
 * for lang/en.json), and the `:param` names each message takes.
 *
 * The default locale (`app.locale`) is the Source of Truth for keys and params: the generated `t()` takes its keys
 * (Problems P-055, P-056), and every other locale must have exactly its keys and params (Problem P-057). Package
 * translations under lang/vendor/ are not read.
 */
final class Translations
{
    /** A `:param` placeholder, as Laravel's translator replaces it (`:name`, `:Name` or `:NAME` for the param `name`). */
    private const string PARAM = '/:([A-Za-z][A-Za-z0-9_]*)/';

    public function __construct(
        private readonly string $langPath,
        public readonly string $defaultLocale,
    ) {}

    public static function ofApp(): self
    {
        return new self(lang_path(), config()->string('app.locale'));
    }

    /**
     * The default locale first, then every other locale that has lang files, by name.
     *
     * @return non-empty-list<string>
     */
    public function locales(): array
    {
        $others = [];

        if (is_dir($this->langPath)) {
            foreach (Finder::create()->in($this->langPath)->depth(0)->directories()->exclude('vendor') as $directory) {
                $others[] = $directory->getFilename();
            }

            foreach (Finder::create()->in($this->langPath)->depth(0)->files()->name('*.json') as $file) {
                $others[] = $file->getFilenameWithoutExtension();
            }
        }

        $others = array_values(array_diff(array_unique($others), [$this->defaultLocale]));
        sort($others);

        return [$this->defaultLocale, ...$others];
    }

    /**
     * Every message of the locale, by the key `__()` takes. The JSON file's messages win over a PHP file's with the
     * same key, as in Laravel's translator.
     *
     * @return array<string, string>
     */
    public function messages(string $locale): array
    {
        $messages = [];
        $directory = "{$this->langPath}/{$locale}";

        if (is_dir($directory)) {
            foreach (Finder::create()->in($directory)->files()->name('*.php')->sortByName() as $file) {
                $group = str_replace('\\', '/', substr($file->getRelativePathname(), 0, -strlen('.php')));

                foreach (Arr::dot($this->requireLangFile($file)) as $key => $message) {
                    if (is_string($message)) {
                        $messages["{$group}.{$key}"] = $message;
                    }
                }
            }
        }

        $json = "{$this->langPath}/{$locale}.json";

        if (is_file($json)) {
            $decoded = json_decode((string) file_get_contents($json), true);

            if (! is_array($decoded)) {
                throw new RuntimeException("{$this->relative($json)} is not a JSON object of messages.");
            }

            foreach ($decoded as $key => $message) {
                if (is_string($message)) {
                    $messages[(string) $key] = $message;
                }
            }
        }

        ksort($messages, SORT_STRING);

        return $messages;
    }

    /**
     * The `:param` names a message takes, as `__()` and `t()` name them: `:seconds`, `:Seconds` and `:SECONDS` are
     * all filled by the param `seconds`.
     *
     * @return list<string>
     */
    public static function params(string $message): array
    {
        preg_match_all(self::PARAM, $message, $matches);

        $params = array_map(
            fn (string $placeholder): string => strlen($placeholder) > 1 && strtoupper($placeholder) === $placeholder
                ? strtolower($placeholder)
                : lcfirst($placeholder),
            $matches[1],
        );

        $params = array_values(array_unique($params));
        sort($params);

        return $params;
    }

    /**
     * Problem P-057: what each locale other than the default lacks or adds compared to it, one line per key. Empty
     * when every locale has exactly the default locale's keys, each with the same params.
     *
     * @return list<string>
     */
    public function parityProblems(): array
    {
        $default = $this->messages($this->defaultLocale);
        $problems = [];

        foreach (array_slice($this->locales(), 1) as $locale) {
            $messages = $this->messages($locale);

            foreach ($default as $key => $message) {
                if (! array_key_exists($key, $messages)) {
                    $problems[] = "{$locale}: {$key} is missing";

                    continue;
                }

                $expected = self::params($message);
                $actual = self::params($messages[$key]);

                foreach (array_diff($expected, $actual) as $param) {
                    $problems[] = "{$locale}: {$key} lacks :{$param}";
                }

                foreach (array_diff($actual, $expected) as $param) {
                    $problems[] = "{$locale}: {$key} has :{$param}, which the {$this->defaultLocale} message doesn't take, so t() never fills it";
                }
            }

            foreach (array_keys(array_diff_key($messages, $default)) as $key) {
                $problems[] = "{$locale}: {$key} is not a key of the default locale ({$this->defaultLocale})";
            }
        }

        return $problems;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function requireLangFile(SplFileInfo $file): array
    {
        $contents = (static fn (string $path): mixed => require $path)($file->getPathname());

        if (! is_array($contents)) {
            throw new RuntimeException("{$this->relative($file->getPathname())} doesn't return an array of messages.");
        }

        return $contents;
    }

    private function relative(string $path): string
    {
        return str_starts_with($path, base_path().'/') ? substr($path, strlen(base_path()) + 1) : $path;
    }
}
