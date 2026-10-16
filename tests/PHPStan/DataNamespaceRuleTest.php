<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use StrictlyLaravel\PHPStan\DataNamespaceRule;

/**
 * @extends StrictlyRuleTestCase<DataNamespaceRule>
 */
final class DataNamespaceRuleTest extends StrictlyRuleTestCase
{
    protected function getRule(): Rule
    {
        return new DataNamespaceRule;
    }

    public function test_only_data_classes_live_in_the_data_namespace(): void
    {
        $tip = 'Only laravel-data classes belong in App\Data: move helpers to App\Support and enums to App\Enums.';

        $this->analyse([__DIR__.'/data/data-namespace.php'], [
            ['Class App\Data\ProjectFormatter is in the App\Data namespace but is not a Data class.', 11, $tip],
            ['Interface App\Data\HasLabel is in the App\Data namespace but is not a Data class.', 13, $tip],
            ['Enum App\Data\Visibility is in the App\Data namespace but is not a Data class.', 17, $tip],
            ['Class App\Data\Issues\IssueQuery is in the App\Data namespace but is not a Data class.', 28, $tip],
        ]);
    }
}
