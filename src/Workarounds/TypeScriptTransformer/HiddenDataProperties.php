<?php

namespace StrictlyLaravel\Workarounds\TypeScriptTransformer;

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\TypeScriptTransformer\PhpNodes\PhpPropertyNode;
use Spatie\TypeScriptTransformer\Transformers\ClassPropertyProcessors\ClassPropertyProcessor;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;

/**
 * Problem P-071 (docs/problems.md). Upstream: no upstream issue filed (laravel-typescript-transformer 3.3:
 * `DataClassPropertyProcessor` only drops a property marked with both laravel-data's and typescript-transformer's
 * `#[Hidden]`, combining the checks with `&&`).
 *
 * A property marked with laravel-data's `#[Hidden]` is never sent, so it is left out of the generated type, and `tsc`
 * rejects reading it. Delete this class once upstream honours laravel-data's attribute on its own.
 */
final class HiddenDataProperties implements ClassPropertyProcessor
{
    public function execute(PhpPropertyNode $phpPropertyNode, ?TypeNode $annotation, TypeScriptProperty $property): ?TypeScriptProperty
    {
        return $phpPropertyNode->getAttributes(Hidden::class) === [] ? $property : null;
    }
}
