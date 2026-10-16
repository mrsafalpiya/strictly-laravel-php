<?php

namespace StrictlyLaravel\Workarounds\LaravelData;

use DateTimeInterface;
use Illuminate\Contracts\Config\Repository;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\RuleInferrers\AttributesRuleInferrer;
use Spatie\LaravelData\RuleInferrers\BuiltInTypesRuleInferrer;
use Spatie\LaravelData\RuleInferrers\RequiredRuleInferrer;
use StrictlyLaravel\Data\DateTimeCast;

/**
 * Applies the laravel-data workarounds to the app's `data` config before laravel-data reads it, whether the app
 * published config/data.php or not, so the rules inferred from a Data class's property types match its generated
 * TypeScript and its iterables hold what their types say:
 *
 * - the rule inferrers IntegerRuleInferrer (P-019), PresentArrayRuleInferrer (P-018), ListItemsRuleInferrer (P-022)
 *   and DateFormatRuleInferrer (P-075) run right after laravel-data's BuiltInTypesRuleInferrer, before
 *   AttributesRuleInferrer, so an attribute on the property still has the last word; LiteralRuleInferrer (P-020) runs
 *   last;
 *
 * - Problem P-021: `features.cast_and_transform_iterables` is turned on, so `/** @var list<IssueStatus> *\/ array`
 *   holds enums, not the raw strings laravel-data 4 leaves by default (upstream: off by default until its next major);
 *
 * - the global cast for `DateTimeInterface` is DateTimeCast, which reads `toISOString()`'s timestamp after the formats
 *   of `data.date_format` (Problem P-173) and, through InterfaceTypedDateCast, a property typed `CarbonInterface` or
 *   `DateTimeInterface` as a CarbonImmutable instead of failing every value (Problem P-189).
 *
 * Applying it again changes nothing. There is no switch to turn any of it off (ADR-0007: no feature toggles).
 */
final class StrictInputConfig
{
    /** Run in this order right after BuiltInTypesRuleInferrer. */
    private const array AFTER_BUILT_IN_TYPES = [
        IntegerRuleInferrer::class,
        PresentArrayRuleInferrer::class,
        ListItemsRuleInferrer::class,
        DateFormatRuleInferrer::class,
    ];

    public static function apply(Repository $config): void
    {
        $inferrers = $config->get('data.rule_inferrers');

        $config->set('data.rule_inferrers', self::ruleInferrers(is_array($inferrers) ? array_values($inferrers) : []));
        $config->set('data.features.cast_and_transform_iterables', true);

        // Problems P-173 and P-189: unless the app casts dates with a class of its own.
        if ($config->get('data.casts.'.DateTimeInterface::class) === DateTimeInterfaceCast::class) {
            $config->set('data.casts.'.DateTimeInterface::class, DateTimeCast::class);
        }
    }

    /**
     * @param  list<mixed>  $inferrers
     * @return list<mixed>
     */
    public static function ruleInferrers(array $inferrers): array
    {
        $ours = [...self::AFTER_BUILT_IN_TYPES, LiteralRuleInferrer::class];
        $inferrers = array_values(array_filter($inferrers, fn (mixed $inferrer): bool => ! in_array($inferrer, $ours, true)));

        // The position laravel-data's own inferrers would give them, when the app's list lacks them.
        $anchor = array_search(BuiltInTypesRuleInferrer::class, $inferrers, true);
        $anchor = $anchor !== false ? $anchor + 1 : array_search(AttributesRuleInferrer::class, $inferrers, true);

        if ($anchor === false) {
            $required = array_search(RequiredRuleInferrer::class, $inferrers, true);
            $anchor = $required !== false ? $required + 1 : count($inferrers);
        }

        array_splice($inferrers, $anchor, 0, self::AFTER_BUILT_IN_TYPES);
        $inferrers[] = LiteralRuleInferrer::class;

        return $inferrers;
    }
}
