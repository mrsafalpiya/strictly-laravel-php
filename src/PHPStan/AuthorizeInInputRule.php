<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Scalar\Int_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use StrictlyLaravel\Data\InputData;

/**
 * Problem P-030: a controller action that takes an InputData has validated it before its body runs, so authorizing in
 * the body (`Gate::authorize()`, `$this->authorize()`, `abort_if(…, 403)`) gives an unauthorized user a 422 listing
 * the fields instead of a 403. The input class's `authorize()` runs before validation, so authorization goes there.
 * Matching is syntactic: the common authorization calls, written directly in the action.
 *
 * @implements Rule<ClassMethod>
 */
final class AuthorizeInInputRule implements Rule
{
    private const string GATE = 'Illuminate\Support\Facades\Gate';

    /** Gate facade methods that throw when the ability is denied. */
    private const array GATE_METHODS = ['authorize', 'allowif', 'denyif'];

    /** The AuthorizesRequests trait's methods that throw when the ability is denied. */
    private const array CONTROLLER_METHODS = ['authorize', 'authorizeforuser'];

    public function __construct(
        private ReflectionProvider $reflection,
    ) {}

    public function getNodeType(): string
    {
        return ClassMethod::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $controller = ControllerAction::of($node, $scope);
        $input = $controller === null ? null : $this->input($node, $scope);

        if ($controller === null || $input === null || $node->stmts === null) {
            return [];
        }

        $errors = [];

        foreach ((new NodeFinder)->findInstanceOf($node->stmts, Node\Expr\CallLike::class) as $call) {
            $authorization = $this->authorization($call, $scope);

            if ($authorization === null) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                '%s::%s() authorizes with %s after %s was validated, so an unauthorized user gets a 422 listing the fields instead of a 403.',
                $controller->getName(), $node->name->toString(), $authorization, $input,
            ))
                ->identifier('strictly.authorizeInInput')
                ->line($call->getStartLine())
                ->tip("Authorize in {$input}::authorize() instead (its parameters are injected), which runs before validation.")
                ->build();
        }

        return $errors;
    }

    /** The action's first InputData parameter's class. */
    private function input(ClassMethod $method, Scope $scope): ?string
    {
        foreach ($method->params as $param) {
            $type = $param->type instanceof NullableType ? $param->type->type : $param->type;

            $class = $type instanceof Name ? $scope->resolveName($type) : null;

            if ($class !== null && $this->reflection->hasClass($class) && $this->reflection->getClass($class)->isSubclassOf(InputData::class)) {
                return $class;
            }
        }

        return null;
    }

    private function authorization(Node $call, Scope $scope): ?string
    {
        if ($call instanceof StaticCall && $call->class instanceof Name && $call->name instanceof Identifier
            && $scope->resolveName($call->class) === self::GATE && in_array($call->name->toLowerString(), self::GATE_METHODS, true)) {
            return "Gate::{$call->name->toString()}()";
        }

        if ($call instanceof MethodCall && $call->var instanceof Variable && $call->var->name === 'this' && $call->name instanceof Identifier
            && in_array($call->name->toLowerString(), self::CONTROLLER_METHODS, true)) {
            return "\$this->{$call->name->toString()}()";
        }

        if ($call instanceof FuncCall && $call->name instanceof Name) {
            $function = $call->name->toLowerString();
            $args = $call->getArgs();
            $code = match ($function) {
                'abort' => $args[0] ?? null,
                'abort_if', 'abort_unless' => $args[1] ?? null,
                default => null,
            };

            if ($code !== null && $code->value instanceof Int_ && $code->value->value === 403) {
                return $function === 'abort' ? 'abort(403)' : "{$function}(…, 403)";
            }
        }

        return null;
    }
}
