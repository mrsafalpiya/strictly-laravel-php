<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;

/**
 * Whether a method is a controller action, as the PHPStan rules about request input see it: a public, non-static
 * method other than the constructor, of a concrete class in a `Controllers` namespace or named `…Controller`.
 * Routes don't exist at analysis time, so this is the convention Laravel's `make:controller` follows.
 */
final class ControllerAction
{
    public static function of(ClassMethod $method, Scope $scope): ?ClassReflection
    {
        $class = $scope->getClassReflection();

        if ($class === null || ! self::isController($class)) {
            return null;
        }

        if (! $method->isPublic() || $method->isStatic() || $method->isAbstract() || str_starts_with($method->name->toString(), '__') && $method->name->toLowerString() !== '__invoke') {
            return null;
        }

        return $class;
    }

    /**
     * The controller a node is in, in any of its methods (a private helper reading the request is its action reading
     * it) or a closure inside one; null outside a controller.
     */
    public static function enclosing(Scope $scope): ?ClassReflection
    {
        $class = $scope->getClassReflection();

        return $class !== null && self::isController($class) ? $class : null;
    }

    /** Whether a class is a controller: a concrete class in a `Controllers` namespace or named `…Controller`. */
    public static function isController(ClassReflection $class): bool
    {
        if (! $class->isClass() || $class->isAnonymous() || $class->isAbstract()) {
            return false;
        }

        $name = $class->getName();

        return str_contains($name, '\\Controllers\\') || str_ends_with($name, 'Controller');
    }

    /**
     * Whether the method a node is in (for a closure, the method around it) takes an instance of the class: an input
     * or query class.
     *
     * @param  class-string  $base
     */
    public static function takes(Scope $scope, string $base): bool
    {
        $function = $scope->getFunction();

        if ($function === null) {
            return false;
        }

        foreach ($function->getOnlyVariant()->getParameters() as $parameter) {
            if ((new ObjectType($base))->isSuperTypeOf(TypeCombinator::removeNull($parameter->getType()))->yes()) {
                return true;
            }
        }

        return false;
    }
}
