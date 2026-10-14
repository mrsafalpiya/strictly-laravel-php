<?php

namespace StrictlyLaravel\Workarounds\TypeScriptTransformer;

use Closure;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptGeneric;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIdentifier;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;
use StrictlyLaravel\TypeScript\PhpDocTypeFix;

/**
 * Problem P-033 (docs/problems.md). Upstream: no upstream issue filed (typescript-transformer 3.3).
 *
 * typescript-transformer turns `array<value-of<IssueStatus>, int>` into `Record<'todo' | 'done', number>`, claiming
 * every status is a key, while PHPStan only proves each key is a status: the server sends the statuses it has. This
 * makes it `Partial<Record<IssueStatus, number>>`. Delete this class once upstream generates a partial record.
 */
final class EnumKeyedMaps implements PhpDocTypeFix
{
    public function transpile(TypeNode $type, ?PhpClassNode $class, Closure $transpile, Closure $resolveClass): ?TypeScriptNode
    {
        if (
            ! $type instanceof GenericTypeNode
            || ! in_array(strtolower($type->type->name), ['array', 'non-empty-array'], true)
            || count($type->genericTypes) !== 2
        ) {
            return null;
        }

        [$key, $value] = $type->genericTypes;

        if (! $key instanceof GenericTypeNode || $key->type->name !== 'value-of' || count($key->genericTypes) !== 1) {
            return null;
        }

        $enum = $key->genericTypes[0];

        return new TypeScriptGeneric(new TypeScriptIdentifier('Partial'), [
            new TypeScriptGeneric(new TypeScriptIdentifier('Record'), [$transpile($enum), $transpile($value)]),
        ]);
    }
}
