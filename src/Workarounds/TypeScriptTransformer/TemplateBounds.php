<?php

namespace StrictlyLaravel\Workarounds\TypeScriptTransformer;

use BackedEnum;
use ReflectionClass;
use Spatie\LaravelData\Contracts\BaseData;
use Spatie\TypeScriptTransformer\Actions\TranspilePhpStanTypeToTypeScriptNodeAction;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptGeneric;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptGenericTypeParameter;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIdentifier;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNumber;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptObject;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptString;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnion;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnknown;
use Spatie\TypeScriptTransformer\Visitor\Visitor;
use Spatie\TypeScriptTransformer\Visitor\VisitorOperation;
use StrictlyLaravel\TypeScript\ClassTemplates;

/**
 * Problem P-062 (docs/problems.md). Upstream: no upstream issue filed (typescript-transformer 3.3's DocTypeResolver
 * keeps only a `@template`'s name).
 *
 * A generic Data class (`@template TItem of IssueData`) is generated as `ListingData<TItem>`, so TypeScript code may
 * instantiate it with any type. This writes each template's bound as its type parameter's constraint
 * (`ListingData<TItem extends IssueData>`). A bound naming a class with no TypeScript type would be an unresolved
 * reference and fail generation (UnmappedClassCheck), so those are written as what the browser receives instead:
 *
 * - an abstract Data class or one of laravel-data's own (`of Data`, `of InputData`) as `object`;
 * - `of \BackedEnum` as `string | number`, a backed enum's value;
 * - any other class with no TypeScript type (`of Model`, `of \Stringable`, `of \UnitEnum`) is dropped, leaving the
 *   parameter unconstrained as before this class.
 *
 * Delete this class once typescript-transformer writes template bounds.
 */
final class TemplateBounds
{
    public static function apply(Transformed $transformed, PhpClassNode $class, TranspilePhpStanTypeToTypeScriptNodeAction $transpiler): void
    {
        $alias = $transformed->getNode();

        if (! $alias instanceof TypeScriptAlias || ! $alias->identifier instanceof TypeScriptGeneric) {
            return;
        }

        $templates = ClassTemplates::of($class);
        $names = array_keys($templates);

        foreach ($alias->identifier->genericTypes as $parameter) {
            if (! $parameter instanceof TypeScriptGenericTypeParameter || ! $parameter->identifier instanceof TypeScriptIdentifier) {
                continue;
            }

            $bound = ($templates[$parameter->identifier->name] ?? null)?->bound;

            if ($bound !== null) {
                $parameter->extends = self::withoutUntypedClasses($transpiler->execute($bound, $class, $names));
            }
        }
    }

    private static function withoutUntypedClasses(TypeScriptNode $bound): ?TypeScriptNode
    {
        $bound = Visitor::create()->before(
            fn (TypeScriptReference $node): VisitorOperation => $node->reference instanceof ClassStringReference
                ? self::typeOf($node->reference->classString) ?? VisitorOperation::keep()
                : VisitorOperation::keep(),
            [TypeScriptReference::class],
        )->execute($bound);

        // `T extends unknown` (or a union with it) constrains nothing.
        $members = $bound instanceof TypeScriptUnion ? $bound->types : [$bound];

        foreach ($members as $member) {
            if ($member instanceof TypeScriptUnknown) {
                return null;
            }
        }

        return $bound;
    }

    /**
     * What a bound naming this class is written as, or null when the class has a TypeScript type of its own.
     */
    private static function typeOf(string $class): ?VisitorOperation
    {
        if (is_a($class, BaseData::class, true)) {
            return str_starts_with($class, 'Spatie\\LaravelData\\') || (new ReflectionClass($class))->isAbstract()
                ? VisitorOperation::replace(new TypeScriptObject([]))
                : null;
        }

        if (enum_exists($class)) {
            return is_a($class, BackedEnum::class, true) ? null : VisitorOperation::replace(new TypeScriptUnknown);
        }

        if (is_a($class, BackedEnum::class, true)) {
            return VisitorOperation::replace(new TypeScriptUnion([new TypeScriptString, new TypeScriptNumber]));
        }

        return VisitorOperation::replace(new TypeScriptUnknown);
    }
}
