<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use StrictlyLaravel\PHPStan\DataListRule;

/**
 * @extends StrictlyRuleTestCase<DataListRule>
 */
final class DataListRuleTest extends StrictlyRuleTestCase
{
    protected function getRule(): Rule
    {
        return new DataListRule;
    }

    public function test_arrays_a_data_class_sends_that_typescript_reads_as_lists_are_declared_lists(): void
    {
        $error = static fn (string $property, string $type): string => "App\\Data\\Lists\\{$property} is typed {$type}, which TypeScript reads as an array, but PHP sends an array whose keys have a gap as a JSON object.";
        $tip = static fn (string $item): string => "Declare it list<{$item}>. A query's ->get()->map(…)->all() is one; after a filter(), unique() or sortBy(), which keep the keys they leave, add ->values() before ->all(), which renumbers them. A map keyed by ints is a JSON object, not an array: key it by string (array<string, {$item}>), or make it a list of Data classes.";

        $this->analyse([__DIR__.'/data/data-lists.php'], [
            [$error('LabelsPage::$declared', 'array<int, LabelData>'), 29, $tip('LabelData')],
            [$error('LabelsPage::$intKeyed', 'array<int, LabelData>'), 47, $tip('LabelData')],
            [$error('LabelsPage::$unkeyed', 'array<LabelData>'), 48, $tip('LabelData')],
            [$error('LabelsPage::$bracketed', 'LabelData[]'), 49, $tip('LabelData')],
            [$error('LabelsPage::$nonEmpty', 'non-empty-array<int, LabelData>'), 50, $tip('LabelData')],
            [$error('LabelsPage::$ranged', 'array<int<0, max>, LabelData>'), 51, $tip('LabelData')],
            [$error('LabelsPage::$nullable', '(array<int, LabelData> | null)'), 52, $tip('LabelData')],
            [$error('LabelsPage::$shortNullable', '?array<int, LabelData>'), 53, $tip('LabelData')],
            [$error('LabelsPage::$inline', 'array<int, LabelData>'), 60, $tip('LabelData')],
            [$error('LabelUpdateData::$labels', '(Optional | array<int, LabelData>)'), 72, $tip('LabelData')],
        ]);
    }
}
