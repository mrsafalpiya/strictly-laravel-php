<?php

namespace StrictlyLaravel\Tests\PHPStan;

use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;
use StrictlyLaravel\PHPStan\DataShapeRule;

/**
 * @extends RuleTestCase<DataShapeRule>
 */
final class DataShapeRuleTest extends RuleTestCase
{
    protected function getRule(): Rule
    {
        return new DataShapeRule;
    }

    public function test_data_properties_have_no_shapes_contract_coverage_cant_see(): void
    {
        $shape = static fn (string $property, string $shape): string => "App\\Data\\Shapes\\{$property} has an array shape ({$shape}) in its type, inside which Contract Coverage can't see.";
        $union = static fn (string $property, string $item): string => "App\\Data\\Shapes\\{$property} holds items of a union type ({$item}), whose members Contract Coverage can't tell apart.";
        $nested = static fn (string $property, string $item): string => "App\\Data\\Shapes\\{$property} holds items that are lists or maps themselves ({$item}), inside which Contract Coverage can't see.";

        $shapeTip = 'Model the shape as a Data class, whose every property Contract Coverage checks: list<ProjectSummaryData> with public ?string $lead on ProjectSummaryData, instead of list<array{lead: ?string}>.';
        $dataUnionTip = "Model the item as a Data class whose one property is the union, its variants told apart by a literal discriminant (/** @var 'issue' */ public string \$type): list<FocusItemData> with public IssueData|MilestoneData \$subject on FocusItemData. Contract Coverage checks each variant of such a property (P-132).";
        $unionTip = 'Model the item as a Data class whose property has the union type: list<EstimateData> with public int|string $value on EstimateData, whose every member Contract Coverage checks.';
        $nestedTip = 'Model the item as a Data class that holds the inner list or map: list<ColumnData> with /** @var list<IssueData> */ public array $issues on ColumnData, instead of list<list<IssueData>>.';

        $issueOrMilestone = 'App\Data\Shapes\IssueData|App\Data\Shapes\MilestoneData';

        $this->analyse([__DIR__.'/data/data-shapes.php'], [
            [$shape('ProjectsPage::$counts', 'array{open: int, closed: int}'), 36, $shapeTip],
            [$shape('ProjectsPage::$projects', 'array{lead: string|null}'), 51, $shapeTip],
            [$union('ProjectsPage::$estimates', 'int|string'), 52, $unionTip],
            [$union('ProjectsPage::$focus', $issueOrMilestone), 53, $dataUnionTip],
            [$nested('ProjectsPage::$columns', 'list<App\Data\Shapes\IssueData>'), 54, $nestedTip],
            [$nested('ProjectsPage::$issuesByStatus', 'array<int, App\Data\Shapes\IssueData>'), 55, $nestedTip],
            [$union('ProjectsPage::$leads', 'string|null'), 56, $unionTip],
            [$shape('ProjectsPage::$owner', 'array{name: string}'), 57, $shapeTip],
            [$shape('ProjectsPage::$grouped', 'array{lead: string|null}'), 58, $shapeTip],
            [$union('ProjectsPage::$pinned', $issueOrMilestone), 59, $dataUnionTip],
            [$shape('ProjectsPage::$recent', 'array{id: int}'), 60, $shapeTip],
            [$shape('ProjectsPage::$archived', 'array{id: int}'), 62, $shapeTip],
            [$union('StoreIssueData::$estimates', 'int|string'), 72, $unionTip],
            [$shape('IssueFiltersData::$sort', 'array{field: string}'), 82, $shapeTip],
            [$shape('ReportPage::$rows', 'array{id: int}'), 159, $shapeTip],
        ]);
    }
}
