<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use StrictlyLaravel\PHPStan\NestedInputDataRule;

/**
 * @extends StrictlyRuleTestCase<NestedInputDataRule>
 */
final class NestedInputDataRuleTest extends StrictlyRuleTestCase
{
    protected function getRule(): Rule
    {
        return new NestedInputDataRule($this->createReflectionProvider());
    }

    public function test_a_data_class_with_rules_nested_in_an_input_extends_input_data(): void
    {
        $nests = static fn (string $property, string $through = ''): string => sprintf(
            'App\Data\Nested\%s nests App\Data\Nested\TaskData%s, whose rules() replace the rules inferred from its types because it doesn\'t extend StrictlyLaravel\Data\InputData.',
            $property, $through === '' ? '' : " (through App\\Data\\Nested\\{$through})",
        );
        $tip = 'Make App\Data\Nested\TaskData extend StrictlyLaravel\Data\InputData: its rules() then merge with the inferred ones, as the input\'s own do.';

        $this->analyse([__DIR__.'/data/nested-inputs.php'], [
            [$nests('StoreTaskData::$task'), 58, $tip],
            [$nests('StoreTasksData::$tasks'), 68, $tip],
            [$nests('StoreTasksData::$byKey'), 71, $tip],
            [$nests('StoreTasksData::$collection'), 74, $tip],
            [$nests('StoreTasksData::$attributed'), 77, $tip],
            [$nests('StoreChecklistData::$checklist', 'ChecklistData'), 87, $tip],
            [$nests('TaskFiltersData::$task'), 93, $tip],
        ]);
    }
}
