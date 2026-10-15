<?php

namespace StrictlyLaravel\Workarounds\LaravelData;

use DateTimeInterface;
use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Casts\Cast;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Casts\IterableItemCast;
use Spatie\LaravelData\Enums\DataTypeKind;
use Spatie\LaravelData\RuleInferrers\RuleInferrer;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Validation\PropertyRules;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use StrictlyLaravel\Data\DateOnlyCast;

/**
 * Problem P-075 (docs/problems.md). Upstream: no upstream issue filed.
 *
 * laravel-data infers no rule for a `DateTimeInterface` property, so a value its DateTimeInterfaceCast can't read
 * (`2026-10-14`, `tomorrow` for `public CarbonImmutable $startsAt`) passes validation and the cast throws
 * CannotCastDate: a 500 instead of a 422 on the field. This adds DateFormatRule with the formats the property's
 * DateTimeInterfaceCast reads: its `#[WithCast(DateTimeInterfaceCast::class, format: …)]` format, otherwise the formats
 * of `data.date_format` (DATE_ATOM by default). A list of dates (`list<CarbonImmutable>`) has every item validated. A
 * `#[DateOnly]` property gets `date_format:Y-m-d`, the rule DateOnlyCast applies, so its error comes with the other
 * fields' instead of only once they all pass. Null and a missing key are left to the nullable, required and Optional
 * rules, and an explicit `#[DateFormat]` has the last word: nothing is added then. A property whose cast is some other
 * class is left to that cast. Runs right after laravel-data's BuiltInTypesRuleInferrer (StrictInputConfig). Delete
 * this class once laravel-data infers a date rule itself.
 */
final class DateFormatRuleInferrer implements RuleInferrer
{
    public function handle(DataProperty $property, PropertyRules $rules, ValidationContext $context): PropertyRules
    {
        if ($property->attributes->has(DateFormat::class)) {
            return $rules;
        }

        $item = $property->type->iterableItemType;

        if ($property->type->kind === DataTypeKind::Array && $item !== null && is_a($item, DateTimeInterface::class, true)) {
            $formats = self::formatsOf(self::first(app(DataConfig::class)->casts->findCastsForIterableType($item)));

            return $formats === null ? $rules : $rules->add(new Rule(DateFormatRule::ofEachItem($formats)));
        }

        if ($property->type->type->findAcceptedTypeForBaseType(DateTimeInterface::class) === null) {
            return $rules;
        }

        // The cast laravel-data tries first: the property's own, otherwise the global one for its type.
        $cast = $property->cast ?? self::first(app(DataConfig::class)->casts->findCastsForValue($property));

        if ($cast instanceof DateOnlyCast) {
            return $rules->add(new DateFormat(DateOnlyCast::FORMAT));
        }

        $formats = self::formatsOf($cast);

        return $formats === null ? $rules : $rules->add(new Rule(DateFormatRule::of($formats)));
    }

    /**
     * @param  iterable<mixed>  $casts
     */
    private static function first(iterable $casts): Cast|IterableItemCast|null
    {
        foreach ($casts as $cast) {
            return $cast instanceof Cast || $cast instanceof IterableItemCast ? $cast : null;
        }

        return null;
    }

    /**
     * The formats a DateTimeInterfaceCast reads a date in, or null for any other cast.
     *
     * @return non-empty-list<string>|null
     */
    private static function formatsOf(Cast|IterableItemCast|null $cast): ?array
    {
        if (! $cast instanceof DateTimeInterfaceCast) {
            return null;
        }

        // DateTimeInterfaceCast keeps its format protected; null means `data.date_format`, as in its castValue().
        $format = (fn (): mixed => $this->format)->call($cast) ?? config('data.date_format');
        $formats = array_values(array_filter(is_array($format) ? $format : [$format], is_string(...)));

        return $formats === [] ? null : $formats;
    }
}
