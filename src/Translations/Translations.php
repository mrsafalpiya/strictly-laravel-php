<?php

namespace StrictlyLaravel\Translations;

use Illuminate\Support\Arr;
use Illuminate\Translation\MessageSelector;
use ParseError;
use RuntimeException;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * The app's own translations (ADR-0006): every locale's messages under lang/, flattened to the keys Laravel's `__()`
 * takes (`auth.throttle` for lang/en/auth.php, `admin/users.title` for lang/en/admin/users.php, the message itself
 * for lang/en.json), and the `:param` names each message takes.
 *
 * The default locale (`app.locale`) is the Source of Truth for keys, params and which messages are pluralised: the
 * generated `t()` and `choice()` take its keys (Problems P-055, P-056, P-136), and every other locale must have
 * exactly its keys and params, pluralised where it is (Problem P-057). Package translations under lang/vendor/ are not
 * read.
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
     * Problem P-137: the keys of the locale that name a group of messages rather than one (`dates.months` for
     * `'months' => ['Jan', …]` in lang/en/dates.php), which `__()` returns as an array.
     *
     * @return list<string>
     */
    public function groups(string $locale): array
    {
        $groups = [];
        $directory = "{$this->langPath}/{$locale}";

        if (is_dir($directory)) {
            foreach (Finder::create()->in($directory)->files()->name('*.php')->sortByName() as $file) {
                $group = str_replace('\\', '/', substr($file->getRelativePathname(), 0, -strlen('.php')));
                array_push($groups, ...self::nestedGroups($this->requireLangFile($file), $group));
            }
        }

        return $groups;
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
     * Problem P-136: whether the message is pluralised, its forms separated by `|` (`'apple|apples'`,
     * `'{0} No issues|{1} One issue|[2,*] :count issues'`). Laravel's `trans_choice()` picks one of its forms, and
     * `__()` renders it whole, so the client renders it with `choice()`, never `t()`. Laravel has no escape for a
     * literal `|` (Problem P-161).
     */
    public static function isPlural(string $message): bool
    {
        return str_contains($message, '|');
    }

    /**
     * Problem P-057: what each locale other than the default lacks or adds compared to it, one line per key. Empty
     * when every locale has exactly the default locale's keys, each with the same params and pluralised where the
     * default locale's message is (Problem P-136), unless the locale's plural rule has a single form. The number of
     * forms may differ: each locale's plural rule has its own.
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

                if (self::isPlural($message) && ! self::isPlural($messages[$key]) && self::hasPluralForms($locale)) {
                    $problems[] = "{$locale}: {$key} isn't plural, but the {$this->defaultLocale} message is, so choice() can't pick its form";
                } elseif (! self::isPlural($message) && self::isPlural($messages[$key])) {
                    $problems[] = "{$locale}: {$key} is plural, but the {$this->defaultLocale} message isn't, so t() renders every form";
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
     * Whether the locale's plural rule has more than one form. In one with a single form (Japanese, Chinese, Korean,
     * Turkish…) Laravel's `trans_choice()` and the client's `choice()` always take the first form, so a message there
     * needn't be pluralised where the default locale's is.
     */
    private static function hasPluralForms(string $locale): bool
    {
        $selector = new MessageSelector;

        foreach ([0, 1, 2, 3, 5, 11, 21, 100, 101] as $count) {
            if ($selector->getPluralIndex($locale, $count) !== 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<array-key, mixed>  $messages
     * @return list<string>
     */
    private static function nestedGroups(array $messages, string $prefix): array
    {
        $groups = [];

        foreach ($messages as $key => $message) {
            if (is_array($message)) {
                $groups[] = "{$prefix}.{$key}";
                array_push($groups, ...self::nestedGroups($message, "{$prefix}.{$key}"));
            }
        }

        return $groups;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function requireLangFile(SplFileInfo $file): array
    {
        try {
            $contents = (static fn (string $path): mixed => require $path)($file->getPathname());
        } catch (ParseError $error) {
            throw new RuntimeException("{$this->relative($file->getPathname())}:{$error->getLine()}: {$error->getMessage()}", previous: $error);
        }

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
