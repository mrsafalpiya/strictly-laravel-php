<?php

namespace StrictlyLaravel\TypeScript;

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
 * Problem P-061 (docs/problems.md): typescript-transformer writes a reference to a PHP class it has no type for as
 * `undefined`, with only a warning, so `tsc` then trusts a property that is never `undefined`. Run after references
 * are connected, this fails generation instead, naming the property and the class, and the previous output stays.
 *
 * A visitor class rather than a closure: typescript-transformer reflects on closures' source, which a closure that
 * tracks its position can't satisfy.
 */
final class UnmappedClassCheck extends VisitorClosure
{
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

        if ($node instanceof TypeScriptReference && $node->referenced === null && $node->reference instanceof ClassStringReference) {
            $where = $this->property === null ? $this->typeName : "{$this->typeName}.{$this->property}";

            throw new UnmappedClassException(
                "{$where} is typed with the PHP class {$node->reference->classString}, which has no TypeScript type, so it "
                .'would be generated as `undefined` (Problem P-061). Use a Data class, a backed enum or a scalar instead.',
            );
        }

        return VisitorOperation::keep();
    }
}
