<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use StrictlyLaravel\PHPStan\DataClassFinalRule;

/**
 * @extends RuleTestCase<DataClassFinalRule>
 */
final class DataClassFinalRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new DataClassFinalRule;
    }

    public function test_concrete_data_classes_anywhere_must_be_final(): void
    {
        $tip = "Its generated TypeScript type describes exactly this class; a subclass would send props the type doesn't declare.";

        $this->analyse([__DIR__.'/data/data-classes.php'], [
            ['Data class App\Data\IssueData must be final.', 10, $tip],
            ['Data class App\Data\CommentData must be final.', 14, $tip],
            ['Data class App\Data\FilterDto must be final.', 16, $tip],
            ['Data class App\Support\ReportData must be final.', 24, $tip],
        ]);
    }
}
