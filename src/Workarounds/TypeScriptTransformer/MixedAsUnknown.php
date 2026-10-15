<?php

namespace StrictlyLaravel\Workarounds\TypeScriptTransformer;

use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAny;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptArray;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptGeneric;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIdentifier;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptString;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnion;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnknown;
use Spatie\TypeScriptTransformer\Visitor\VisitorClosure;
use Spatie\TypeScriptTransformer\Visitor\VisitorClosureType;
use Spatie\TypeScriptTransformer\Visitor\VisitorOperation;

/**
 * Problem P-062 (docs/problems.md). Upstream: no upstream issue filed (typescript-transformer 3.3 transpiles `mixed`
 * to `any`, in a property's PHP type and in its PHPDoc; https://github.com/spatie/typescript-transformer/issues/58
 * asked for configurable fallbacks against v2).
 *
 * `any` turns off `tsc` for the value and everything read from it. These are the places typescript-transformer writes
 * it for a value that can cross a Type Boundary, each replaced by the type that makes a reader narrow first:
 *
 * - `mixed` (`public mixed $meta`, `array<string, mixed>`, `list<mixed>`) becomes `unknown`;
 * - an array with no item type (`array`, which PHPStan max only lets through from a vendor class) becomes
 *   `unknown[] | Record<string, unknown>`: json_encode() sends a PHP array as a JSON list or as an object.
 *
 * It also writes `any` for a PHPDoc `callable` with no signature, left alone: no JSON value is a callable.
 *
 * Delete this class once typescript-transformer generates `unknown` for these (or can be configured to).
 */
final class MixedAsUnknown extends VisitorClosure
{
    public function __construct()
    {
        parent::__construct(
            fn () => null,
            [TypeScriptAny::class, TypeScriptArray::class],
            VisitorClosureType::Before,
        );
    }

    public function run(TypeScriptNode $node, array &$metadata): VisitorOperation
    {
        return match (true) {
            $node instanceof TypeScriptAny => VisitorOperation::replace(new TypeScriptUnknown),
            $node instanceof TypeScriptArray && $node->types === [] => VisitorOperation::replace(new TypeScriptUnion([
                new TypeScriptArray([new TypeScriptUnknown]),
                new TypeScriptGeneric(new TypeScriptIdentifier('Record'), [new TypeScriptString, new TypeScriptUnknown]),
            ])),
            default => VisitorOperation::keep(),
        };
    }
}
