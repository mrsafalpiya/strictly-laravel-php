<?php

namespace StrictlyLaravel\TypeScript;

use Closure;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;

/**
 * One fix to how typescript-transformer turns a PHPDoc type into TypeScript, applied by PhpDocTypeTranspiler. Each
 * fix is its own class, so it can be deleted once upstream ships the behaviour.
 */
interface PhpDocTypeFix
{
    /**
     * The TypeScript for the type, or null to leave it to typescript-transformer.
     *
     * @param  Closure(TypeNode): TypeScriptNode  $transpile  transpiles a nested type (with every fix applied)
     * @param  Closure(string): ?class-string  $resolveClass  resolves a class name as written in the class's file
     */
    public function transpile(TypeNode $type, ?PhpClassNode $class, Closure $transpile, Closure $resolveClass): ?TypeScriptNode;
}
