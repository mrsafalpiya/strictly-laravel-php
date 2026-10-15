<?php

namespace StrictlyLaravel\TypeScript;

use Closure;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Spatie\TypeScriptTransformer\PhpNodes\PhpPropertyNode;
use Spatie\TypeScriptTransformer\Transformers\ClassPropertyProcessors\ClassPropertyProcessor;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptArray;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNumber;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptRaw;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptString;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnion;
use StrictlyLaravel\Routes\RouteKey;
use StrictlyLaravel\Routes\RouteKeys;

/**
 * Problems P-003, P-128: a Data property that holds a model's key (StrictlyLaravel\Routes\RouteKeys) is generated as
 * that key's brand, `Key<'Issue', 'id', number>`: the type the model's route parameters take when bound by that field,
 * and the inputs that declare it. A list of keys is branded per item; `null` stays `null`.
 */
final class RouteKeyTypes implements ClassPropertyProcessor
{
    private ?RouteKeys $keys = null;

    /**
     * @param  Closure(): RouteKeys  $resolveKeys  Read once, on the first property: the routes are loaded by then.
     */
    public function __construct(private readonly Closure $resolveKeys) {}

    public function execute(PhpPropertyNode $phpPropertyNode, ?TypeNode $annotation, TypeScriptProperty $property): TypeScriptProperty
    {
        $this->keys ??= ($this->resolveKeys)();

        $key = $this->keys->forProperty($phpPropertyNode->getDeclaringClass()->getName(), $phpPropertyNode->getName());

        if ($key !== null) {
            $property->type = self::brand($property->type, $key);
        }

        return $property;
    }

    private static function brand(TypeScriptNode $type, RouteKey $key): TypeScriptNode
    {
        if ($type instanceof TypeScriptUnion) {
            return new TypeScriptUnion(array_map(fn (TypeScriptNode $member): TypeScriptNode => self::brand($member, $key), $type->types));
        }

        if ($type instanceof TypeScriptArray) {
            return new TypeScriptArray(array_map(fn (TypeScriptNode $item): TypeScriptNode => self::brand($item, $key), $type->types));
        }

        if ($type instanceof TypeScriptNumber || $type instanceof TypeScriptString) {
            return new TypeScriptRaw($key->typeScript($type instanceof TypeScriptNumber ? 'number' : 'string'));
        }

        return $type;
    }
}
