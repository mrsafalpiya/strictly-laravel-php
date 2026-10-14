<?php

namespace StrictlyLaravel\TypeScript;

use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use ReflectionClass;
use ReflectionProperty;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\PhpNodes\PhpPropertyNode;
use Spatie\TypeScriptTransformer\TypeResolvers\DocTypeResolver;

/**
 * The PHPDoc type a property's TypeScript type is generated from, found where typescript-transformer looks for it (a
 * class `@property` tag, then the constructor's `@param` for a promoted property, then the property's `@var`), with the
 * class whose imports resolve the names in it. Anything that must agree with the generated type, such as the
 * validation rules inferred for an input (Workarounds\LaravelData\LiteralRuleInferrer), reads the type through this.
 */
final readonly class PropertyAnnotation
{
    private function __construct(
        public TypeNode $type,
        public PhpClassNode $scope,
    ) {}

    /**
     * @param  class-string  $class
     */
    public static function of(string $class, string $property): ?self
    {
        $node = new PhpClassNode(new ReflectionClass($class));
        $resolver = new DocTypeResolver;

        $tagged = $resolver->class($node)->properties[$property] ?? null;

        if ($tagged !== null) {
            return new self($tagged->type, $node);
        }

        $constructor = $node->hasMethod('__construct') ? $node->getMethod('__construct') : null;
        $parameter = $constructor === null ? null : ($resolver->method($constructor)->parameters[$property] ?? null);

        if ($constructor !== null && $parameter !== null) {
            return new self($parameter->type, $constructor->getDeclaringClass());
        }

        if (! property_exists($class, $property)) {
            return null;
        }

        $propertyNode = new PhpPropertyNode(new ReflectionProperty($class, $property));
        $var = $resolver->property($propertyNode);

        return $var === null ? null : new self($var->type, $propertyNode->getDeclaringClass());
    }
}
