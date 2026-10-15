<?php

namespace StrictlyLaravel\PHPStan;

use Illuminate\Translation\TranslationServiceProvider;
use ReflectionClass;
use StrictlyLaravel\Translations\Translations;

/**
 * Problem P-137: the messages a translation call in PHP can name, by key. The default locale's own messages (its PHP
 * lang files and JSON file, read as `strictly:generate` reads them), over Laravel's own lang files (`validation.*`,
 * `auth.*`, `passwords.*`, `pagination.*`), which the translator falls back to when the app hasn't published them
 * (Problem P-138), as its loader merges the two. Read once, when the first call is checked: Larastan has booted the
 * app by then, so `lang_path()` and `app.locale` are the app's.
 */
final class TranslationCatalog
{
    /** @var array<string, string>|null */
    private ?array $messages = null;

    public function __construct(
        private ?string $langPath = null,
        private ?string $defaultLocale = null,
        private ?string $frameworkLangPath = null,
    ) {}

    public function langPath(): string
    {
        return $this->langPath ??= lang_path();
    }

    public function defaultLocale(): string
    {
        return $this->defaultLocale ??= config()->string('app.locale');
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        // Laravel ships its lang files in English only.
        return $this->messages ??= array_replace(
            (new Translations($this->frameworkLangPath(), 'en'))->messages('en'),
            (new Translations($this->langPath(), $this->defaultLocale()))->messages($this->defaultLocale()),
        );
    }

    private function frameworkLangPath(): string
    {
        return $this->frameworkLangPath ??= dirname((string) (new ReflectionClass(TranslationServiceProvider::class))->getFileName()).'/lang';
    }
}
