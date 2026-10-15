<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use StrictlyLaravel\PHPStan\TranslationCatalog;
use StrictlyLaravel\PHPStan\TranslationRule;

/**
 * @extends RuleTestCase<TranslationRule>
 */
final class TranslationRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        $data = __DIR__.'/data/translations';

        return new TranslationRule(new TranslationCatalog("{$data}/lang", 'en', "{$data}/framework"));
    }

    public function test_a_translation_key_used_in_php_is_defined_in_the_default_locale(): void
    {
        $unknown = 'Add it to lang/en/<group>.php or lang/en.json, or fix the key.';

        $this->analyse([__DIR__.'/data/translations.php'], [
            ["Translation key 'projects.flash.saevd' isn't defined in locale en.", 20, $unknown],
            ["Translation key 'validation.requird' isn't defined in locale en.", 21, $unknown],
            ["Translation key 'projects.titel' isn't defined in locale en.", 27, $unknown],
            [
                '__() is passed a translation key built at runtime.',
                28,
                'Its key and params can\'t be checked. Pass a literal key, or a union of them such as a match over an enum, or suppress with @phpstan-ignore strictly.translationDynamic (why every key it builds exists).',
            ],
            [
                '__(\'projects.flash.saved\') is passed params built at runtime.',
                29,
                'They can\'t be checked against its message\'s :params. Pass an array literal, or suppress with @phpstan-ignore strictly.translationDynamic (why they match).',
            ],
            ["Translation 'projects.flash.saved' needs the param 'key'.", 34, 'Its message takes :key.'],
            ["Translation 'projects.flash.saved' takes no param 'name'.", 35, 'Its message takes :key.'],
            ["Translation 'projects.flash.saved' needs the param 'key'.", 36, 'Its message takes :key.'],
            ["Translation 'projects.flash.saved' takes no param 'kye'.", 36, 'Its message takes :key.'],
            ["Translation 'Welcome back, :name!' needs the param 'name'.", 39, 'Its message takes :name.'],
            ["Translation 'issues.count' needs the param 'project'.", 47, 'Its message takes :count and :project; trans_choice() fills :count from the number.'],
            ["Translation 'issues.count' is pluralised: use trans_choice().", 48, '__() renders every form of it, separated by |.'],
            ["Translation 'projects.title' isn't pluralised: use __().", 49, 'It has no | forms for trans_choice() to pick from.'],
            ["Translation 'issues.count' is pluralised: use Lang::choice().", 50, 'Lang::get() renders every form of it, separated by |.'],
            ["Translation key 'projects.titel' isn't defined in locale en.", 51, $unknown],
            ["Translation 'projects.title' isn't pluralised: use Translator::get().", 53, 'It has no | forms for Translator::choice() to pick from.'],
        ]);
    }
}
