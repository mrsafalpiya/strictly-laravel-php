<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\Data\QueryData;

/**
 * Problem P-168: a controller method that takes no input class, or a closure route, hands the request to no app code
 * (`$create->handle($request)`, `new CreateProject($request)`, `CreateProject::run($request)`,
 * `ImportProjects::dispatch($request)`). InputReadRule sees reads only in a controller's own methods and closures, so
 * the code it is handed to could read the payload unseen, and no type describes that payload (P-112).
 *
 * Rejecting every read outside a controller isn't possible: middleware, a FormRequest's own methods and the framework's
 * customisation callbacks (Fortify's `authenticateUsing()`) read the request by design. So the check sits at the
 * hand-off. App code is a method (a constructor for `new`) declared by a class in the app's namespace, other than
 * middleware's (`App\Http\Middleware\`), a FormRequest's (`App\Http\Requests\`) and a controller's, whose reads
 * InputReadRule checks itself. A method declared outside the app's namespace (`toResponse($request)`, Fortify, `Auth`,
 * `Request::createFrom()`) is the framework's or a vendor's, out of reach. So is app code that gets the request some
 * other way, from the container, `request()` or the facade, or inside another value (Problem P-201).
 *
 * A method that takes an input class may hand the request on, as it may read it (InputReadRule): the Contract its
 * requests record checks every key read from the request, wherever it is read (P-024).
 *
 * @implements Rule<CallLike>
 */
final class RequestHandOffRule implements Rule
{
    /** The app's namespaces whose code reads the request by design: middleware and FormRequests. */
    private const array READERS = ['Http\\Middleware\\', 'Http\\Requests\\'];

    public function __construct(
        /** The app's root namespace, `App\`: Laravel reads it from composer.json's PSR-4 autoload of `app/`. */
        private ?string $appNamespace = null,
    ) {}

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if ($node->isFirstClassCallable() || ! array_any($node->getArgs(), fn (Arg $arg): bool => $this->isRequest($scope->getType($arg->value)))) {
            return [];
        }

        $controller = ControllerAction::enclosing($scope);

        if ($controller === null && ! RouteClosure::encloses($scope)) {
            return [];
        }

        if ($controller !== null && ControllerAction::takes($scope, InputData::class)) {
            return [];
        }

        $method = $this->calledMethod($node, $scope);

        if ($method === null || ! $this->isAppCode($method->getDeclaringClass(), $scope)) {
            return [];
        }

        $to = $node instanceof New_
            ? "new {$method->getDeclaringClass()->getName()}()"
            : "{$method->getDeclaringClass()->getName()}::{$method->getName()}()";

        if ($controller === null) {
            return [
                RuleErrorBuilder::message(sprintf(
                    'A closure route hands the request to %s, where nothing types the input it reads: a typed form sends only the fields of a controller action\'s input class.',
                    $to,
                ))
                    ->identifier('strictly.requestHandOff')
                    ->tip(sprintf(
                        'Move the route to a controller action that takes an input class (a final Data class extending %s), and pass the input class, or the values the code needs (such as $request->user()), instead of the request: Wayfinder brands a route function with the input class of the controller action it runs, never with a closure\'s.',
                        InputData::class,
                    ))
                    ->build(),
            ];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s::%s() hands the request to %s, where nothing types the input it reads: a typed form sends only the fields of its action\'s input class.',
                $controller->getName(), $scope->getFunctionName() ?? '{closure}', $to,
            ))
                ->identifier('strictly.requestHandOff')
                ->tip(sprintf(
                    'Take an input class on the action (a final Data class extending %s) and pass it, or the values the code needs (such as $request->user()), instead of the request: the input class types the route\'s form and its validation errors. A GET\'s query string goes in a query class (extending %s) instead.',
                    InputData::class, QueryData::class,
                ))
                ->build(),
        ];
    }

    /** A request: Laravel's (a FormRequest too) or Symfony's, which it extends; nullable or not. */
    private function isRequest(Type $type): bool
    {
        return (new ObjectType('Symfony\Component\HttpFoundation\Request'))->isSuperTypeOf(TypeCombinator::removeNull($type))->yes();
    }

    /** The method a call runs: the constructor for `new`; null for a function or a method PHPStan can't resolve. */
    private function calledMethod(CallLike $call, Scope $scope): ?ExtendedMethodReflection
    {
        // PHPStan also analyses `$service?->handle($request)` as a MethodCall.
        [$type, $name] = match (true) {
            $call instanceof MethodCall => [
                $scope->getType($call->var), $call->name instanceof Identifier ? $call->name->toString() : null,
            ],
            $call instanceof StaticCall => [
                $call->class instanceof Name ? $scope->resolveTypeByName($call->class) : $scope->getType($call->class),
                $call->name instanceof Identifier ? $call->name->toString() : null,
            ],
            $call instanceof New_ => [$scope->getType($call), '__construct'],
            default => [null, null],
        };

        $type = $type === null ? null : TypeCombinator::removeNull($type);

        if ($type === null || $name === null || ! $type->hasMethod($name)->yes()) {
            return null;
        }

        return $scope->getMethodReflection($type, $name);
    }

    /**
     * Whether a class's code is the app's own, and could read the request unseen: in the app's namespace, and neither
     * middleware, a FormRequest nor a controller (the one the call is in, its parents included).
     */
    private function isAppCode(ClassReflection $class, Scope $scope): bool
    {
        $namespace = $this->appNamespace ??= app()->getNamespace();
        $name = $class->getName();

        if (! str_starts_with($name, $namespace)) {
            return false;
        }

        foreach (self::READERS as $reader) {
            if (str_starts_with($name, $namespace.$reader)) {
                return false;
            }
        }

        $caller = $scope->getClassReflection();

        return ! ControllerAction::isController($class)
            && ($caller === null || $caller->getName() !== $name && ! $caller->isSubclassOf($name));
    }
}
