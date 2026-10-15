<?php

namespace StrictlyLaravel\TypeScript;

use BackedEnum;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use Spatie\TypeScriptTransformer\Actions\FindClassNameFqcnAction;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;

/**
 * The values of a PHPDoc literal type, as the generated TypeScript literal sends them: string, int and float literals,
 * class constants and enum cases (their backing value). `'monday'|'sunday'` gives `['monday', 'sunday']`, and
 * `ActivityType::Commented` gives `['commented']`. The validation rules inferred for an input
 * (Workarounds\LaravelData\LiteralRuleInferrer) and Contract Coverage's discriminated unions (Coverage\ShapeCoverage)
 * both read literals through this, so they agree with the generated type.
 */
final class LiteralValues
{
    /** @var array<string, list<string|int|float>|null> */
    private static array $properties = [];

    /**
     * The values a property's PHPDoc type allows, `null` aside, when it is a literal or a union of literals; null when
     * it isn't one (or has no PHPDoc type).
     *
     * @param  class-string  $class
     * @return list<string|int|float>|null
     */
    public static function ofProperty(string $class, string $property): ?array
    {
        $key = "{$class}::{$property}";

        if (! array_key_exists($key, self::$properties)) {
            $annotation = PropertyAnnotation::of($class, $property);

            self::$properties[$key] = $annotation === null ? null : self::of(self::withoutNull($annotation->type), $annotation->scope);
        }

        return self::$properties[$key];
    }

    /**
     * The values of a literal or a union of literals, or null when it isn't one.
     *
     * @return list<string|int|float>|null
     */
    public static function of(TypeNode $type, PhpClassNode $scope): ?array
    {
        $members = $type instanceof UnionTypeNode ? $type->types : [$type];
        $values = [];

        foreach ($members as $member) {
            if (! $member instanceof ConstTypeNode) {
                return null;
            }

            $value = self::value($member, $scope);

            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        return $values;
    }

    /** The type without its `null` member (`?T` and `T|null` give `T`). */
    public static function withoutNull(TypeNode $type): TypeNode
    {
        if ($type instanceof NullableTypeNode) {
            return $type->type;
        }

        if (! $type instanceof UnionTypeNode) {
            return $type;
        }

        $types = array_values(array_filter(
            $type->types,
            fn (TypeNode $member): bool => ! ($member instanceof IdentifierTypeNode && strtolower($member->name) === 'null'),
        ));

        return count($types) === 1 ? $types[0] : new UnionTypeNode($types);
    }

    private static function value(ConstTypeNode $type, PhpClassNode $scope): string|int|float|null
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
