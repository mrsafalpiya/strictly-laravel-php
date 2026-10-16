<?php

namespace StrictlyLaravel\Tests\Install;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use StrictlyLaravel\Install\ViteLintPlugin;

/**
 * The lint config can't switch a rule the Overlay turns on off, or relax it, for every file or for a `lint.overrides`
 * entry's files (P-190, ADR-0007). The harness scenarios `lint-rule-switched-off-fails-install-check` and
 * `rules-left-on-reinstall-passes` run the installer itself.
 */
final class ViteLintPluginTest extends TestCase
{
    private string $viteConfig;

    protected function setUp(): void
    {
        $this->viteConfig = sys_get_temp_dir().'/strictly-vite-'.bin2hex(random_bytes(4)).'.ts';
    }

    protected function tearDown(): void
    {
        @unlink($this->viteConfig);
    }

    #[Test]
    public function it_rejects_a_plugin_rule_turned_off(): void
    {
        $this->lintConfig(['strictly/typed-forms' => "'off'"]);

        $this->expectExceptionObject(new RuntimeException(
            'vite.config.ts turns `strictly/typed-forms` off; Strictly Laravel has no toggles (ADR-0007): remove the setting, '
            .'and suppress single lines with `// oxlint-disable-next-line strictly/typed-forms -- reason`.',
        ));

        ViteLintPlugin::register($this->viteConfig);
    }

    #[Test]
    public function it_rejects_a_plugin_rule_turned_off_as_a_number(): void
    {
        $this->lintConfig(['strictly/typed-page-props' => '0']);

        $this->expectExceptionMessage('vite.config.ts turns `strictly/typed-page-props` off; Strictly Laravel has no toggles (ADR-0007)');

        ViteLintPlugin::register($this->viteConfig);
    }

    #[Test]
    public function it_rejects_a_rule_turned_down_to_a_warning(): void
    {
        $this->lintConfig(['typescript/no-non-null-assertion' => '"warn"']);

        $this->expectExceptionObject(new RuntimeException(
            'vite.config.ts turns `typescript/no-non-null-assertion` down to a warning; Strictly Laravel has no toggles (ADR-0007): '
            .'remove the setting, and suppress single lines with `// oxlint-disable-next-line typescript/no-non-null-assertion -- reason`.',
        ));

        ViteLintPlugin::rejectAssertions($this->viteConfig);
    }

    #[Test]
    public function it_rejects_an_unsafe_any_rule_turned_off(): void
    {
        $this->lintConfig(['typescript/no-unsafe-assignment' => "'off'"]);

        $this->expectExceptionMessage('vite.config.ts turns `typescript/no-unsafe-assignment` off; Strictly Laravel has no toggles (ADR-0007)');

        ViteLintPlugin::rejectAssertions($this->viteConfig);
    }

    #[Test]
    public function it_adds_the_unsafe_any_rules_to_an_app_that_has_only_the_assertion_rules(): void
    {
        $this->lintConfig(array_fill_keys(array_keys(ViteLintPlugin::UNSAFE_ANY_RULES), null));

        self::assertTrue(ViteLintPlugin::rejectAssertions($this->viteConfig));
        self::assertStringContainsString(<<<'TS'
                    rules: {
                        'typescript/no-unsafe-argument': 'error',
                        'typescript/no-unsafe-assignment': 'error',
                        'typescript/no-unsafe-call': 'error',
                        'typescript/no-unsafe-member-access': 'error',
                        'typescript/no-unsafe-return': 'error',
                        'typescript/consistent-type-assertions': [

            TS, (string) file_get_contents($this->viteConfig));
        self::assertFalse(ViteLintPlugin::rejectAssertions($this->viteConfig));
    }

    #[Test]
    public function it_rejects_a_rule_with_other_options(): void
    {
        $this->lintConfig(['typescript/consistent-type-assertions' => "['error', { assertionStyle: 'as' }]"]);

        $this->expectExceptionMessage(
            "vite.config.ts sets `typescript/consistent-type-assertions` to `['error', { assertionStyle: 'as' }]`, "
            ."not `['error', { assertionStyle: 'never' }]`; Strictly Laravel has no toggles (ADR-0007)",
        );

        ViteLintPlugin::rejectAssertions($this->viteConfig);
    }

    #[Test]
    public function it_rejects_a_rule_with_options_given_none(): void
    {
        $this->lintConfig(['typescript/consistent-type-assertions' => "'error'"]);

        $this->expectExceptionMessage("vite.config.ts sets `typescript/consistent-type-assertions` to `'error'`, not `['error', { assertionStyle: 'never' }]`");

        ViteLintPlugin::rejectAssertions($this->viteConfig);
    }

    #[Test]
    public function it_rejects_a_rule_turned_off_for_the_files_of_an_override(): void
    {
        $this->lintConfig(lint: <<<'TS'
                    overrides: [
                        {
                            files: ['resources/js/legacy/**'],
                            rules: {
                                'strictly/typed-requests': 'error',
                                'typescript/consistent-type-assertions': 'off',
                            },
                        },
                    ],

            TS);

        $this->expectExceptionObject(new RuntimeException(
            'vite.config.ts turns `typescript/consistent-type-assertions` off in a `lint.overrides` entry; Strictly Laravel has no toggles '
            .'(ADR-0007): remove the setting, and suppress single lines with `// oxlint-disable-next-line typescript/consistent-type-assertions -- reason`.',
        ));

        ViteLintPlugin::rejectAssertions($this->viteConfig);
    }

    #[Test]
    public function it_names_every_rule_switched_off(): void
    {
        $this->lintConfig(['strictly/typed-forms' => "'off'", 'strictly/typed-requests' => "'warn'"]);

        try {
            ViteLintPlugin::register($this->viteConfig);
            self::fail('No exception thrown');
        } catch (RuntimeException $e) {
            self::assertStringContainsString('turns `strictly/typed-forms` off;', $e->getMessage());
            self::assertStringContainsString('turns `strictly/typed-requests` down to a warning;', $e->getMessage());
        }
    }

    #[Test]
    public function it_accepts_a_rule_on_in_any_form_and_changes_nothing(): void
    {
        $this->lintConfig([
            'strictly/typed-forms' => '2',
            'strictly/typed-requests' => '["error"]',
            'strictly/typed-page-props' => '[2]',
            'typescript/no-non-null-assertion' => '"error"',
            'typescript/consistent-type-assertions' => '["error", { "assertionStyle": "never", }]',
        ], <<<'TS'
                    overrides: [
                        {
                            files: ['tests/**'],
                            rules: { 'strictly/no-hardcoded-urls': 'error', 'no-console': 'off' },
                        },
                    ],

            TS);
        $before = file_get_contents($this->viteConfig);

        self::assertFalse(ViteLintPlugin::register($this->viteConfig));
        self::assertFalse(ViteLintPlugin::rejectAssertions($this->viteConfig));
        self::assertSame($before, file_get_contents($this->viteConfig));
    }

    #[Test]
    public function it_turns_on_a_rule_whose_setting_is_commented_out(): void
    {
        $this->lintConfig([
            'strictly/typed-forms' => null,
            'strictly/typed-requests' => null,
            'strictly/typed-page-props' => "'error',\n// 'strictly/typed-forms': 'error',\n/* 'strictly/typed-requests': 'off' */",
        ]);

        self::assertTrue(ViteLintPlugin::register($this->viteConfig));
        self::assertStringContainsString("\n            'strictly/typed-forms': 'error',\n", (string) file_get_contents($this->viteConfig));
        self::assertStringContainsString("\n            'strictly/typed-requests': 'error',\n", (string) file_get_contents($this->viteConfig));
    }

    /**
     * Write a vite.config.ts whose lint config turns on every rule the Overlay does, as strictly:install writes them,
     * except that $settings replaces a rule's setting (null leaves the rule out). $lint goes at the end of the `lint`
     * object.
     *
     * @param  array<string, string|null>  $settings  rule => its setting as TypeScript
     */
    private function lintConfig(array $settings = [], string $lint = ''): void
    {
        $rules = [
            ...ViteLintPlugin::ASSERTION_RULES,
            ...ViteLintPlugin::UNSAFE_ANY_RULES,
            ...array_fill_keys(ViteLintPlugin::RULES, "'error'"),
            ...$settings,
        ];
        $lines = implode('', array_map(
            fn (string $rule, ?string $setting): string => $setting === null ? '' : "            '{$rule}': ".str_replace("\n", "\n            ", $setting).",\n",
            array_keys($rules),
            $rules,
        ));

        file_put_contents($this->viteConfig, <<<TS
            import { defineConfig } from 'vite-plus';

            export default defineConfig({
                lint: {
                    jsPlugins: ['@mrsafalpiya/strictly-laravel/oxlint'],
                    rules: {
            {$lines}        },
                    ignorePatterns: ['vendor/**', 'resources/js/components/ui/*'],
            {$lint}    },
            });

            TS);
    }
}
