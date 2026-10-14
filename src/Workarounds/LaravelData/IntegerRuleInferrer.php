<?php

namespace StrictlyLaravel\Workarounds\LaravelData;

use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\RuleInferrers\RuleInferrer;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Validation\PropertyRules;
use Spatie\LaravelData\Support\Validation\ValidationContext;

/**
 * Problem P-019 (docs/problems.md). Upstream: https://github.com/spatie/laravel-data/pull/814 (an `integer` option,
 * declined).
 *
 * laravel-data infers `numeric` for an `int` property, so `1.5` passes validation and is silently truncated to `1`.
 * This replaces it with `integer` for a property that accepts `int` but not `float`. Runs right after laravel-data's
 * BuiltInTypesRuleInferrer (RuleInference). Delete this class once laravel-data infers `integer` itself.
 */
final class IntegerRuleInferrer implements RuleInferrer
{
    public function handle(DataProperty $property, PropertyRules $rules, ValidationContext $context): PropertyRules
    {
        $type = $property->type;

        if ($type->isMixed || ! $type->acceptsType('int') || $type->acceptsType('float') || ! $rules->hasType(Numeric::class)) {
            return $rules;
        }

        return $rules->removeType(Numeric::class)->add(new IntegerType);
    }
}
