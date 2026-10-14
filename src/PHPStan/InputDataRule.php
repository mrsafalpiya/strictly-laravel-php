<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Spatie\LaravelData\Contracts\BaseData;
use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\Data\QueryData;

/**
 * Every Data class a controller action takes is request input, which laravel-data validates from the request, so it
 * extends InputData (Problems P-014, P-017, P-030): its custom rules() are merged with the inferred ones instead of
 * replacing them, its authorize() runs before validation, and only an InputData parameter brands the action's route,
 * which types its form and validation errors.
 *
 * A GET action's query Data class extends QueryData instead (Problem P-006): it is parsed leniently from the query
 * string, and it types the route function's `query` option.
 *
 * @implements Rule<ClassMethod>
 */
final readonly class InputDataRule implements Rule
{
    /** The base classes a Data class taken by a controller action may extend. */
    public const array REQUEST_DATA = [InputData::class, QueryData::class];

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

        if ($controller === null) {
            return [];
        }

        $errors = [];

        foreach ($node->params as $param) {
            $type = $param->type instanceof NullableType ? $param->type->type : $param->type;

            if (! $type instanceof Name) {
                continue;
            }

            $class = $scope->resolveName($type);

            if (! $this->reflection->hasClass($class)) {
                continue;
            }

            $reflection = $this->reflection->getClass($class);

            if (! $reflection->implementsInterface(BaseData::class)) {
                continue;
            }

            foreach (self::REQUEST_DATA as $base) {
                if ($reflection->getName() === $base || $reflection->isSubclassOf($base)) {
                    continue 2;
                }
            }

            $method = $node->name instanceof Identifier ? $node->name->toString() : '';

            $errors[] = RuleErrorBuilder::message(sprintf(
                "%s::%s() takes %s, which is validated from the request but doesn't extend %s.",
                $controller->getName(), $method, $reflection->getName(), InputData::class,
            ))
                ->identifier('strictly.inputData')
                ->line($param->getStartLine())
                ->tip(sprintf(
                    'Make %s extend %s: its rules() then merge with the inferred ones, authorize() runs before validation, and the route is branded with its type. A GET\'s query string extends %s instead.',
                    $reflection->getName(), InputData::class, QueryData::class,
                ))
                ->build();
        }

        return $errors;
    }
}
