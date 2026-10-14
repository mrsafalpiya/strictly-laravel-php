<?php

namespace StrictlyLaravel\Workarounds\TypeScriptTransformer;

use Inertia\MergeProp;
use Inertia\OnceProp;
use Inertia\ScrollProp;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptGeneric;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\Visitor\VisitorClosure;
use Spatie\TypeScriptTransformer\Visitor\VisitorClosureType;
use Spatie\TypeScriptTransformer\Visitor\VisitorOperation;

/**
 * Problem P-041 (docs/problems.md). Upstream: no upstream issue filed (typescript-transformer 3.3).
 *
 * Inertia resolves its prop wrappers before the JSON is sent, so `ScrollProp<PaginatedDataCollection<int, X>>` reaches
 * the browser as the paginated collection. typescript-transformer has no type for the wrapper and writes
 * `undefined<…>`, and `replaceType(ScrollProp::class, …)` can't help: a replacement keeps the generic arguments, so it
 * writes `never<…>`. This replaces the generic with its argument for the wrappers whose value is always sent:
 * `ScrollProp`, `MergeProp` and `OnceProp`. Deferred and optional props are absent on the first load, so they stay
 * unmapped and fail generation (P-061, P-043). Delete this class once upstream unwraps generic wrappers.
 */
final class InertiaPropWrappers extends VisitorClosure
{
    /** @var list<class-string> */
    private const array WRAPPERS = [ScrollProp::class, MergeProp::class, OnceProp::class];

    public function __construct()
    {
        parent::__construct(fn () => null, [TypeScriptGeneric::class], VisitorClosureType::Before);
    }

    public function run(TypeScriptNode $node, array &$metadata): VisitorOperation
    {
        if (
            ! $node instanceof TypeScriptGeneric
            || ! $node->type instanceof TypeScriptReference
            || ! $node->type->reference instanceof ClassStringReference
            || ! in_array($node->type->reference->classString, self::WRAPPERS, true)
            || count($node->genericTypes) !== 1
        ) {
            return VisitorOperation::keep();
        }

        return VisitorOperation::replace($node->genericTypes[0]);
    }
}
