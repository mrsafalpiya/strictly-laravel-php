<?php

namespace StrictlyLaravel\Tests\Install;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StrictlyLaravel\Install\PhpstanIgnores;

/**
 * phpstan.neon, and every file of the app it includes, ignores no error of a Strictly Laravel rule, by identifier or by
 * message (P-190, ADR-0007). The harness scenarios `phpstan-rule-ignored-fails-install-check` and
 * `rules-left-on-reinstall-passes` run the installer itself.
 */
final class PhpstanIgnoresTest extends TestCase
{
    private const string HEADER = "includes:\n    - vendor/mrsafalpiya/strictly-laravel/extension.neon\n\nparameters:\n    paths:\n        - app/\n";

    private string $app;

    protected function setUp(): void
    {
        $this->app = sys_get_temp_dir().'/strictly-phpstan-'.bin2hex(random_bytes(4));
        mkdir($this->app);
    }

    protected function tearDown(): void
    {
        array_map(unlink(...), glob("{$this->app}/*") ?: []);
        rmdir($this->app);
    }

    #[Test]
    public function it_rejects_an_entry_for_a_rule_identifier(): void
    {
        $this->phpstanNeon("    ignoreErrors:\n        - identifier: strictly.jsonResponse\n");

        $this->expectExceptionObject(new RuntimeException(
            'phpstan.neon ignores `strictly.jsonResponse` errors; Strictly Laravel has no toggles (ADR-0007): remove the entry, '
            .'and suppress single lines with `// @phpstan-ignore strictly.jsonResponse (reason)`.',
        ));

        PhpstanIgnores::reject("{$this->app}/phpstan.neon");
    }

    #[Test]
    public function it_rejects_an_entry_for_a_rule_identifier_in_some_paths(): void
    {
        $this->phpstanNeon(<<<'NEON'
                ignoreErrors:
                    -
                        identifiers: [missingType.iterableValue, strictly.keyOf]
                        paths:
                            - app/Data/Legacy/*
                            - app/Data/ImportData.php

            NEON);

        $this->expectExceptionMessage(
            'phpstan.neon ignores `strictly.keyOf` errors in app/Data/Legacy/*, app/Data/ImportData.php; Strictly Laravel has no toggles (ADR-0007)',
        );

        PhpstanIgnores::reject("{$this->app}/phpstan.neon");
    }

    /** @return iterable<string, array{string, string}> */
    public static function messageEntries(): iterable
    {
        yield 'a regex copied from the message' => [
            "        - message: '#^Data class App\\\\Data\\\\IssueData must be final\\.$#'\n          path: app/Data/IssueData.php\n",
            'phpstan.neon ignores `strictly.dataClassFinal` errors by their message in app/Data/IssueData.php',
        ];
        yield 'a regex for part of the message' => [
            "        - '#renders a page whose props#'\n",
            'phpstan.neon ignores `strictly.inertiaRender` errors by their message',
        ];
        yield 'a regex with wildcards, groups and a flag' => [
            "        - message: '~^Translation key ''[a-z.]+'' isn(?:'')t defined in LOCALE \\S+\\.$~i'\n",
            'phpstan.neon ignores `strictly.translationKey` errors by their message',
        ];
        yield 'a raw message' => [
            "        - rawMessage: 'Inertia::render() renders a page whose props have no generated type.'\n",
            'phpstan.neon ignores `strictly.inertiaRender` errors by their message',
        ];
        yield 'one of several messages' => [
            "        - messages:\n            - '#^Unsafe usage of new static#'\n            - '#needs the param#'\n",
            'phpstan.neon ignores `strictly.translationParams` errors by their message',
        ];
    }

    #[Test]
    #[DataProvider('messageEntries')]
    public function it_rejects_an_entry_for_a_rule_message(string $entry, string $error): void
    {
        $this->phpstanNeon("    ignoreErrors:\n{$entry}");

        $this->expectExceptionMessage($error.'; Strictly Laravel has no toggles (ADR-0007)');

        PhpstanIgnores::reject("{$this->app}/phpstan.neon");
    }

    #[Test]
    public function it_rejects_an_entry_in_a_file_phpstan_neon_includes(): void
    {
        $this->phpstanNeon('', "    - phpstan-baseline.neon\n");
        file_put_contents("{$this->app}/phpstan-baseline.neon", <<<'NEON'
            parameters:
                ignoreErrors:
                    -
                        message: '#^Data class App\\Data\\IssueData must be final\.$#'
                        identifier: strictly.dataClassFinal
                        count: 1
                        path: app/Data/IssueData.php

            NEON);

        $this->expectExceptionMessage('phpstan-baseline.neon ignores `strictly.dataClassFinal` errors in app/Data/IssueData.php; Strictly Laravel has no toggles (ADR-0007)');

        PhpstanIgnores::reject("{$this->app}/phpstan.neon");
    }

    #[Test]
    public function it_allows_entries_for_other_errors(): void
    {
        $this->phpstanNeon(<<<'NEON'
                ignoreErrors:
                    - identifier: missingType.iterableValue
                    - '#^Access to an undefined property App\\Models\\User::\$foo\.$#'
                    - rawMessage: 'Unsafe usage of new static().'
                    -
                        message: '#^Data class App\\Data\\IssueData must be final\.$#'
                        identifier: class.notFinal
                    - '#.*#'

            NEON, "    - vendor/larastan/larastan/extension.neon\n    - %rootDir%/../../nesbot/carbon/extension.neon\n");

        self::assertFalse(PhpstanIgnores::reject("{$this->app}/phpstan.neon"));
    }

    #[Test]
    public function it_allows_a_config_without_ignore_errors_or_no_config(): void
    {
        $this->phpstanNeon('');

        self::assertFalse(PhpstanIgnores::reject("{$this->app}/phpstan.neon"));
        self::assertFalse(PhpstanIgnores::reject("{$this->app}/missing.neon"));
    }

    private function phpstanNeon(string $parameters, string $includes = ''): void
    {
        file_put_contents("{$this->app}/phpstan.neon", str_replace("extension.neon\n", "extension.neon\n{$includes}", self::HEADER).$parameters);
    }
}
