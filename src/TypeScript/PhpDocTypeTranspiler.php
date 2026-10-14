<?php

namespace StrictlyLaravel\TypeScript;

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Spatie\TypeScriptTransformer\Actions\TranspilePhpStanTypeToTypeScriptNodeAction;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;

/**
 * typescript-transformer's PHPDoc transpiler with the Overlay's fixes applied first, at every level of a type, so a
 * literal inside a list or a union is fixed too.
 */
final class PhpDocTypeTranspiler extends TranspilePhpStanTypeToTypeScriptNodeAction
{
    /**
     * @param  list<PhpDocTypeFix>  $fixes
     */
    public function __construct(private readonly array $fixes)
    {
        parent::__construct();
    }

    protected function resolve(TypeNode $type, ?PhpClassNode $phpClassNode): TypeScriptNode
    {
        foreach ($this->fixes as $fix) {
            $node = $fix->transpile(
                $type,
                $phpClassNode,
                fn (TypeNode $nested): TypeScriptNode => $this->resolve($nested, $phpClassNode),
                fn (string $class): ?string => $this->resolveClass($class, $phpClassNode),
            );

            if ($node !== null) {
                return $node;
            }
        }

        return parent::resolve($type, $phpClassNode);
    }
}
