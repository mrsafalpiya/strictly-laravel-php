<?php

namespace StrictlyLaravel\Workarounds\TypeScriptTransformer;

use BackedEnum;
use Closure;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFalseNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprFloatNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprIntegerNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprStringNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstExprTrueNode;
use PHPStan\PhpDocParser\Ast\ConstExpr\ConstFetchNode;
use PHPStan\PhpDocParser\Ast\Type\ConstTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptLiteral;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;
use StrictlyLaravel\TypeScript\PhpDocTypeFix;
use UnitEnum;

/**
 * Problem P-032 (docs/problems.md). Upstream: no upstream issue filed (typescript-transformer 3.3).
 *
 * typescript-transformer turns PHPDoc literal types into `unknown`. This turns them into TypeScript literals: the
 * string 'monday', the int 3, the float 1.5, `true`/`false`, a class constant and an enum case (its backing value), so
 * `@var ActivityType::Commented` becomes `'commented'` and Data classes form discriminated unions that an
 * exhaustive switch narrows. Delete this class once upstream transpiles literal types.
 */
final class LiteralTypes implements PhpDocTypeFix
{
    public function transpile(TypeNode $type, ?PhpClassNode $class, Closure $transpile, Closure $resolveClass): ?TypeScriptNode
    {
        if ($type instanceof IdentifierTypeNode) {
            return match (strtolower($type->name)) {
                'true' => new TypeScriptLiteral(true),
                'false' => new TypeScriptLiteral(false),
                default => null,
            };
        }

        if (! $type instanceof ConstTypeNode) {
            return null;
        }

        $expression = $type->constExpr;

        return match (true) {
            $expression instanceof ConstExprStringNode => new TypeScriptLiteral($expression->value),
            $expression instanceof ConstExprIntegerNode => new TypeScriptLiteral((int) str_replace('_', '', $expression->value)),
            $expression instanceof ConstExprFloatNode => new TypeScriptLiteral((float) str_replace('_', '', $expression->value)),
            $expression instanceof ConstExprTrueNode => new TypeScriptLiteral(true),
            $expression instanceof ConstExprFalseNode => new TypeScriptLiteral(false),
            $expression instanceof ConstFetchNode => $this->constant($expression, $resolveClass),
            default => null,
        };
    }

    /**
     * @param  Closure(string): ?class-string  $resolveClass
     */
    private function constant(ConstFetchNode $node, Closure $resolveClass): ?TypeScriptNode
    {
        $class = $resolveClass($node->className);

        if ($class === null || ! defined("{$class}::{$node->name}")) {
            return null;
        }

        $value = constant("{$class}::{$node->name}");

        return match (true) {
            $value instanceof BackedEnum => new TypeScriptLiteral($value->value),
            $value instanceof UnitEnum => null,
            is_scalar($value) => new TypeScriptLiteral($value),
            default => null,
        };
    }
}
