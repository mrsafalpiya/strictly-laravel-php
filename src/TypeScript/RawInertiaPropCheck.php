<?php

namespace StrictlyLaravel\TypeScript;

use Inertia\DeferProp;
use Inertia\OptionalProp;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptGeneric;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIdentifier;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\Visitor\VisitorClosure;
use Spatie\TypeScriptTransformer\Visitor\VisitorClosureType;
use Spatie\TypeScriptTransformer\Visitor\VisitorOperation;

/**
 * Problem P-043: a prop built with `Inertia::defer()` or `Inertia::optional()` is absent until the page loads it, and
 * Inertia's DeferProp and OptionalProp say nothing about the value they hold. Typed as either, a prop has no type to
 * generate, so this fails generation with the typed way instead: a laravel-data lazy, which is generated as an
 * optional key holding the Data class (`stats?: ReportStatsData`), so a component must handle "not loaded yet".
 *
 *     public Lazy|ReportStatsData $stats       // new ReportsPage(stats: Lazy::inertiaDeferred(fn () => …))
 *
 * Runs before UnmappedClassCheck, which would otherwise report these classes without saying what to use.
 */
final class RawInertiaPropCheck extends VisitorClosure
{
    /** Each raw prop class and the laravel-data lazy that sends the same way. */
    private const array LAZY_FOR = [
        DeferProp::class => 'Lazy::inertiaDeferred(fn () => …)',
        OptionalProp::class => 'Lazy::inertia(fn () => …)',
    ];

    private string $typeName = '?';

    private ?string $property = null;

    public function __construct()
    {
        parent::__construct(
            fn () => null,
            [TypeScriptAlias::class, TypeScriptProperty::class, TypeScriptReference::class],
            VisitorClosureType::Before,
        );
    }

    public function run(TypeScriptNode $node, array &$metadata): VisitorOperation
    {
        if ($node instanceof TypeScriptAlias) {
            $identifier = $node->identifier instanceof TypeScriptGeneric ? $node->identifier->type : $node->identifier;
            $this->typeName = $identifier instanceof TypeScriptIdentifier ? $identifier->name : '?';
            $this->property = null;
        }

        if ($node instanceof TypeScriptProperty && $node->name instanceof TypeScriptIdentifier) {
            $this->property = $node->name->name;
        }

        if ($node instanceof TypeScriptReference && $node->reference instanceof ClassStringReference
            && isset(self::LAZY_FOR[$node->reference->classString])) {
            $class = $node->reference->classString;
            $where = $this->property === null ? $this->typeName : "{$this->typeName}.{$this->property}";

            throw new RawInertiaPropException(
                "{$where} is typed with {$class}, which is absent until it is loaded and has no value type: declare it "
                .'`Lazy|YourData` and pass `'.self::LAZY_FOR[$class].'`, so it is generated as an optional prop the '
                .'page component must handle (Problem P-043).',
            );
        }

        return VisitorOperation::keep();
    }
}
