<?php

namespace StrictlyLaravel\Workarounds\LaravelData;

use BackedEnum;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Rule;
use Spatie\LaravelData\Attributes\Validation\ValidationAttribute;
use Spatie\LaravelData\RuleInferrers\RuleInferrer;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Validation\PropertyRules;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\TypeScriptTransformer\Actions\FindClassNameFqcnAction;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use StrictlyLaravel\TypeScript\PropertyAnnotation;

/**
 * Problem P-020 (docs/problems.md). Upstream: no upstream issue filed.
 *
 * A PHPDoc literal union (`@param 'monday'|'sunday' $weekStartsOn`) is generated as the TypeScript literal union
 * `'monday' | 'sunday'` (Workarounds\TypeScriptTransformer\LiteralTypes), but laravel-data validates only the native
 * type, so the server accepts any string. This adds `in:monday,sunday`, from the same PHPDoc type the TypeScript is
 * generated from (PropertyAnnotation): string, int and float literals, class constants and enum cases (their backing
 * value), optionally `|null`. A list of such literals (`list<'low'|'high'>`) has every item validated (ListItemsRule).
 * A `true`/`false` literal adds no rule.
 */
final class LiteralRuleInferrer implements RuleInferrer
{
    /** @var array<string, ValidationAttribute|null> */
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

    private function rule(DataProperty $property): ?ValidationAttribute
    {
        if (! class_exists($property->className)) {
            return null;
        }

        $annotation = PropertyAnnotation::of($property->className, $property->name);

        if ($annotation === null) {
            return null;
        }

        $type = $this->withoutNull($annotation->type);
        $values = $this->literals($type, $annotation->scope);

        if ($values !== null) {
            return new In($values);
        }

        $item = match (true) {
            $type instanceof GenericTypeNode && in_array(strtolower($type->type->name), ['list', 'non-empty-list', 'array', 'non-empty-array'], true) => $type->genericTypes[count($type->genericTypes) - 1] ?? null,
            $type instanceof ArrayTypeNode => $type->type,
            default => null,
        };
        $itemValues = $item === null ? null : $this->literals($this->withoutNull($item), $annotation->scope);

        return $itemValues === null ? null : new Rule(ListItemsRule::oneOf($itemValues));
    }

    private function withoutNull(TypeNode $type): TypeNode
    {
        if ($type instanceof NullableTypeNode) {
            return $type->type;
        }

        if (! $type instanceof UnionTypeNode) {
            return $type;
        }

        $types = array_values(array_filter($type->types, fn (TypeNode $member): bool => ! $this->isNull($member)));

        return count($types) === 1 ? $types[0] : new UnionTypeNode($types);
    }

    private function isNull(TypeNode $type): bool
    {
        return $type instanceof IdentifierTypeNode && strtolower($type->name) === 'null';
    }

    /**
     * The values of a literal or a union of literals, or null when it isn't one.
     *
     * @return list<string|int|float>|null
     */
    private function literals(TypeNode $type, PhpClassNode $scope): ?array
    {
        $members = $type instanceof UnionTypeNode ? $type->types : [$type];
        $values = [];

        foreach ($members as $member) {
            if (! $member instanceof ConstTypeNode) {
                return null;
            }

            $value = $this->value($member, $scope);

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        return $values;
    }

    private function value(ConstTypeNode $type, PhpClassNode $scope): string|int|float|null
    {
        $expression = $type->constExpr;

        if ($expression instanceof ConstExprStringNode) {
            return $expression->value;
        }

        if ($expression instanceof ConstExprIntegerNode) {
            return (int) str_replace('_', '', $expression->value);
        }

        if ($expression instanceof ConstExprFloatNode) {
            return (float) str_replace('_', '', $expression->value);
        }

        if (! $expression instanceof ConstFetchNode) {
            return null;
        }

        $class = (new FindClassNameFqcnAction)->execute($scope, $expression->className);

        if ($class === null || ! defined("{$class}::{$expression->name}")) {
            return null;
        }

        $value = constant("{$class}::{$expression->name}");

        return match (true) {
            $value instanceof BackedEnum => $value->value,
            is_string($value), is_int($value), is_float($value) => $value,
            default => null,
        };
    }
}
