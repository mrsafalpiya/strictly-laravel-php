<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use StrictlyLaravel\PHPStan\RuleMessages;

/**
 * A test of one of the extension's rules. Besides the errors it expects, every error the rule reports with a
 * `strictly.*` identifier must fit one of that identifier's templates in RuleMessages, which the installer reads to tell
 * an `ignoreErrors` entry that ignores the rule by its message (P-190).
 *
 * @template TRule of Rule
 *
 * @extends RuleTestCase<TRule>
 */
abstract class StrictlyRuleTestCase extends RuleTestCase
{
    public function analyse(array $files, array $expectedErrors): void
    {
        parent::analyse($files, $expectedErrors);

        foreach ($this->gatherAnalyserErrors($files) as $error) {
            $identifier = $error->getIdentifier();

            if ($identifier === null || ! str_starts_with($identifier, 'strictly.')) {
                continue;
            }

            $templates = RuleMessages::TEMPLATES[$identifier] ?? [];
            $fits = array_filter($templates, fn (string $template): bool => preg_match(
                '/^'.implode('.*', array_map(fn (string $part): string => preg_quote($part, '/'), explode('%s', $template))).'$/s',
                $error->getMessage(),
            ) === 1);

            self::assertNotEmpty($fits, "The {$identifier} error \"{$error->getMessage()}\" fits none of its templates in "
                .RuleMessages::class.'::TEMPLATES: add one, so the installer recognises an ignoreErrors entry for its message.');
        }
    }
}
