<?php

namespace StrictlyLaravel\PHPStan;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPStan\Analyser\Scope;
use PHPStan\Type\ObjectType;

/**
 * A read of the current request, as the PHPStan rules about request input see it (Problems P-106, P-112): a method
 * call or property fetch on an `Illuminate\Http\Request` (any subclass, so a FormRequest too; `request()` returns one),
 * an array access on one, a call on the Request facade, or the `request('key')` helper, which reads the input.
 */
final readonly class RequestRead
{
    /** The property a request reads its input from when it isn't one of these (`__get`), and so is a key. */
    private const array PROPERTIES = ['attributes', 'request', 'query', 'server', 'files', 'cookies', 'headers'];

    private function __construct(
        /** The method or property read, lowercased: `input`, `query`; `->` for an input key read as a property, `[]` for an array access. */
        public string $accessor,
        /** The read as the rules' messages show it: `input()`, `->query`, `->name`, `['name']`, `Request::input()`, `request()`. */
        public string $shown,
    ) {}

    public static function of(Node $node, Scope $scope): ?self
    {
        return match (true) {
            // PHPStan also analyses `$request?->input()` as a MethodCall and `$request?->name` as a PropertyFetch.
            $node instanceof MethodCall => $node->name instanceof Identifier && self::isRequest($node->var, $scope)
                ? new self($node->name->toLowerString(), "{$node->name->toString()}()")
                : null,
            $node instanceof PropertyFetch => $node->name instanceof Identifier && self::isRequest($node->var, $scope)
                ? self::property($node->name->toString())
                : null,
            $node instanceof ArrayDimFetch => self::isRequest($node->var, $scope)
                ? new self('[]', $node->dim instanceof String_ ? "['{$node->dim->value}']" : '[…]')
                : null,
            $node instanceof StaticCall => $node->name instanceof Identifier && $node->class instanceof Name
                && $scope->resolveName($node->class) === RequestFacade::class
                ? new self($node->name->toLowerString(), "Request::{$node->name->toString()}()")
                : null,
            // `request()` alone returns the request; given a key, it reads the input.
            $node instanceof FuncCall => $node->name instanceof Name && ltrim($node->name->toLowerString(), '\\') === 'request'
                && $node->getArgs() !== []
                ? new self('input', 'request()')
                : null,
            default => null,
        };
    }

    private static function property(string $name): self
    {
        return in_array(strtolower($name), self::PROPERTIES, true)
            ? new self(strtolower($name), "->{$name}")
            : new self('->', "->{$name}");
    }

    private static function isRequest(Expr $expr, Scope $scope): bool
    {
        return (new ObjectType(Request::class))->isSuperTypeOf($scope->getType($expr))->yes();
    }
}
