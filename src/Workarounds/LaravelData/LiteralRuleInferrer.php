<?php

namespace StrictlyLaravel\Workarounds\LaravelData;

use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\RuleInferrers\RuleInferrer;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Validation\PropertyRules;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\LaravelData\Support\Validation\ValidationRule;
use StrictlyLaravel\TypeScript\LiteralValues;
use StrictlyLaravel\TypeScript\PropertyAnnotation;

/**
 * Problem P-020 (docs/problems.md). Upstream: no upstream issue filed.
 *
 * A PHPDoc literal union (`@param 'monday'|'sunday' $weekStartsOn`) is generated as the TypeScript literal union
 * `'monday' | 'sunday'` (Workarounds\TypeScriptTransformer\LiteralTypes), but laravel-data validates only the native
 * type, so the server accepts any string. This adds `in:monday,sunday`, from the same PHPDoc type the TypeScript is
 * generated from (PropertyAnnotation, LiteralValues): string, int and float literals, class constants and enum cases
 * (their backing value), optionally `|null`. A list of such literals (`list<'low'|'high'>`) has every item validated
 * (ListItemsRule). A `true`/`false` literal adds no rule.
 */
final class LiteralRuleInferrer implements RuleInferrer
{
    /** @var array<string, ValidationRule|null> */
    private static array $cache = [];

    public function handle(DataProperty $property, PropertyRules $rules, ValidationContext $context): PropertyRules
    {
        $key = $property->className.'::'.$property->name;

        if (! array_key_exists($key, self::$cache)) {
            self::$cache[$key] = $this->rule($property);
        }

        $rule = self::$cache[$key];

        return $rule === null ? $rules : $rules->add($rule);
    }

    private function rule(DataProperty $property): ?ValidationRule
    {
        if (! class_exists($property->className)) {
            return null;
        }

        $annotation = PropertyAnnotation::of($property->className, $property->name);

        if ($annotation === null) {
            return null;
        }

        $type = LiteralValues::withoutNull($annotation->type);
        $values = LiteralValues::of($type, $annotation->scope);

        if ($values !== null) {
            return new In($values);
        }

        $item = match (true) {
            $type instanceof GenericTypeNode && in_array(strtolower($type->type->name), ['list', 'non-empty-list', 'array', 'non-empty-array'], true) => $type->genericTypes[count($type->genericTypes) - 1] ?? null,
            $type instanceof ArrayTypeNode => $type->type,
            default => null,
        };
        $itemValues = $item === null ? null : LiteralValues::of(LiteralValues::withoutNull($item), $annotation->scope);

        return $itemValues === null ? null : new Rule(ListItemsRule::oneOf($itemValues));
    }
}
