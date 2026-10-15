<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\FunctionReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\ObjectType;

/**
 * A closure route, as the PHPStan rules about request input see it (Problems P-097, P-109, P-151, P-167): a closure
 * given as the action of `Route::get/post/put/patch/delete/options/any/match()`, on the facade, the router or a route
 * registrar (`Route::middleware('auth')->post(…)`). Wayfinder gives its templates only a route's controller and method,
 * never its closure, so nothing about the closure types its route function.
 *
 * A route a test registers (`$this` is a PHPUnit TestCase, as in a Pest test) isn't one: it exists only while that test
 * runs, so no route function is generated for it, and a test route that takes an input class is how a feature test
 * checks that class's validation.
 */
final readonly class RouteClosure
{
    /** The classes that register a route. */
    private const array REGISTRARS = [
        'Illuminate\Support\Facades\Route',
        'Illuminate\Routing\Router',
        'Illuminate\Routing\RouteRegistrar',
    ];

    /** Their methods that register a route with an action, lowercased. */
    private const array VERBS = ['get', 'post', 'put', 'patch', 'delete', 'options', 'any', 'match'];

    private const string TEST_CASE = 'PHPUnit\Framework\TestCase';

    private function __construct(
        public Closure|ArrowFunction $closure,
        /** The route as the rules' messages show it: its HTTP methods and URI, `POST projects`, `GET|POST search`. */
        public string $shown,
    ) {}

    /** The closure route a call registers, or null when it registers none (a controller route, or no route at all). */
    public static function of(CallLike $call, Scope $scope): ?self
    {
        if (! $call instanceof StaticCall && ! $call instanceof MethodCall || ! $call->name instanceof Identifier
            || ! in_array($call->name->toLowerString(), self::VERBS, true)) {
            return null;
        }

        $type = $call instanceof StaticCall
            ? ($call->class instanceof Name ? $scope->resolveTypeByName($call->class) : null)
            : $scope->getType($call->var);
        $method = $type === null ? null : $scope->getMethodReflection($type, $call->name->toString());

        if ($method === null || ! self::registers($method) || self::inTest($scope)) {
            return null;
        }

        $args = array_values(array_filter($call->getArgs(), static fn (Arg $arg): bool => $arg->name === null));
        $closure = array_find($args, static fn (Arg $arg): bool => $arg->value instanceof Closure || $arg->value instanceof ArrowFunction)?->value;

        if (! $closure instanceof Closure && ! $closure instanceof ArrowFunction) {
            return null;
        }

        $verb = $method->getName();
        $match = strtolower($verb) === 'match';
        $methods = $match ? self::methods($args[0]->value ?? null) : strtoupper($verb);
        $uri = $args[$match ? 1 : 0]->value ?? null;

        return new self($closure, sprintf('%s %s', $methods, $uri instanceof String_ ? $uri->value : '…'));
    }

    /**
     * Whether a node is in the body of a closure route (any closure inside it too), from the calls PHPStan is analysing
     * the arguments of: one of them is a route method whose action this closure is.
     */
    public static function encloses(Scope $scope): bool
    {
        foreach ($scope->getFunctionCallStackWithParameters() as [$function, $parameter]) {
            if (self::registers($function) && $parameter?->getName() === 'action') {
                return ! self::inTest($scope);
            }
        }

        return false;
    }

    private static function registers(FunctionReflection|MethodReflection $function): bool
    {
        return $function instanceof MethodReflection
            && in_array($function->getDeclaringClass()->getName(), self::REGISTRARS, true)
            && in_array(strtolower($function->getName()), self::VERBS, true);
    }

    private static function inTest(Scope $scope): bool
    {
        return $scope->hasVariableType('this')->yes()
            && (new ObjectType(self::TEST_CASE))->isSuperTypeOf($scope->getVariableType('this'))->yes();
    }

    /** The HTTP methods of `Route::match()`, `GET|POST`. */
    private static function methods(?Expr $methods): string
    {
        $names = match (true) {
            $methods instanceof String_ => [$methods->value],
            $methods instanceof Array_ => array_map(
                static fn (?Expr $item): string => $item instanceof String_ ? $item->value : '…',
                array_map(static fn ($item): ?Expr => $item?->value, $methods->items),
            ),
            default => ['…'],
        };

        return strtoupper(implode('|', $names));
    }
}
