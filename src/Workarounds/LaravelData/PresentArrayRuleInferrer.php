<?php

namespace StrictlyLaravel\Workarounds\LaravelData;

use Spatie\LaravelData\Attributes\Validation\Present;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Enums\DataTypeKind;
use Spatie\LaravelData\RuleInferrers\RuleInferrer;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Validation\PropertyRules;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Problem P-018 (docs/problems.md). Upstream: declined in the comments of
 * https://github.com/spatie/laravel-data/issues/681 (Laravel's `required` treats `[]` as missing).
 *
 * laravel-data infers `required` for a non-null array, list or Data collection, which rejects `[]`, while its
 * TypeScript type (`string[]`) allows it. This replaces `required` with `present`: the key must be sent, but may hold
 * an empty list. Add `#[Min(1)]` to a list that must not be empty. Runs after laravel-data's RequiredRuleInferrer and
 * before its AttributesRuleInferrer, so an explicit `#[Required]` still applies (RuleInference).
 */
final class PresentArrayRuleInferrer implements RuleInferrer
{
    public function handle(DataProperty $property, PropertyRules $rules, ValidationContext $context): PropertyRules
    {
        $kind = $property->type->kind;
        $isArray = $kind === DataTypeKind::Array || $kind === DataTypeKind::DataArray || $kind === DataTypeKind::DataCollection;

        if (! $isArray || ! $rules->hasType(Required::class)) {
            return $rules;
        }

        return $rules->removeType(Required::class)->prepend(new Present);
    }
}
