<?php

namespace StrictlyLaravel\Workarounds\LaravelData;

use BackedEnum;
use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Enums\DataTypeKind;
use Spatie\LaravelData\RuleInferrers\RuleInferrer;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Validation\PropertyRules;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Problem P-022 (docs/problems.md). Upstream: no upstream issue filed.
 *
 * laravel-data validates a typed list of scalars or backed enums (`/** @var list<IssueStatus> *\/ array $statuses`) only
 * as an `array`, so an invalid item passes validation and then fails while casting: a 500 instead of a 422. This adds
 * ListItemsRule, which validates every item against the list's item type. (Lists of Data objects are validated by
 * laravel-data already.) Delete this class once laravel-data validates list items.
 */
final class ListItemsRuleInferrer implements RuleInferrer
{
    public function handle(DataProperty $property, PropertyRules $rules, ValidationContext $context): PropertyRules
    {
        $item = $property->type->iterableItemType;

        if ($property->type->kind !== DataTypeKind::Array || $item === null) {
            return $rules;
        }

        if (in_array($item, ['int', 'float', 'string', 'bool'], true)) {
            return $rules->add(new Rule(ListItemsRule::ofType($item)));
        }

        if (is_subclass_of($item, BackedEnum::class)) {
            return $rules->add(new Rule(ListItemsRule::ofType($item)));
        }

        return $rules;
    }
}
