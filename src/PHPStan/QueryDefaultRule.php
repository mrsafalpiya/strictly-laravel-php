<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\IntersectionType;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\Property;
use PhpParser\Node\UnionType;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use Spatie\LaravelData\Optional;
use StrictlyLaravel\Data\QueryData;

/**
 * Every property of a query Data class has a default, and the default is what the route function sends when it
 * leaves the key out of the URL, so the server reads the value the client meant:
 *
 * - Problem P-104: a property without a default can't fall back, so a URL without its key (any link that doesn't
 *   set every filter, an old bookmark) fails to build the class: a 500, while `QueryOptions` lets the client leave
 *   every key out. A nullable property without one is `null` and an Optional one is Optional, as laravel-data fills
 *   them.
 * - Problem P-105: Wayfinder's route functions leave a `null` value and an empty list out of the URL, so the server
 *   reads the property's default instead: a nullable property defaults to `null` and a list to `[]`.
 *
 * @implements Rule<InClassNode>
 */
final readonly class QueryDefaultRule implements Rule
{
    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();

        if (! $class->isSubclassOf(QueryData::class)) {
            return [];
        }

        $errors = [];

        foreach ($node->getOriginalNode()->stmts as $statement) {
            if ($statement instanceof ClassMethod && $statement->name->toLowerString() === '__construct') {
                foreach ($statement->params as $param) {
                    if ($param->isPromoted() && $param->var instanceof Expr\Variable && is_string($param->var->name)) {
                        $errors = [...$errors, ...$this->check($class->getName(), $param->var->name, $param->type, $param->default, $param->getStartLine(), $scope)];
                    }
                }
            }

            if ($statement instanceof Property && $statement->isPublic() && ! $statement->isStatic()) {
                foreach ($statement->props as $property) {
                    $errors = [...$errors, ...$this->check($class->getName(), $property->name->toString(), $statement->type, $property->default, $property->getStartLine(), $scope)];
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    private function check(string $class, string $name, Identifier|Name|ComplexType|null $type, ?Expr $default, int $line, Scope $scope): array
    {
        $types = $this->types($type, $scope);

        $nullable = in_array('null', $types, true) || in_array('mixed', $types, true);

        // laravel-data fills an absent Optional property with Optional and an absent nullable one with null.
        if (in_array(strtolower(Optional::class), $types, true) || ($nullable && $default === null)) {
            return [];
        }

        $message = match (true) {
            $default === null => sprintf(
                '%s::$%s has no default, so a query string without "%s" can\'t be parsed: the request fails with a 500.',
                $class, $name, $name,
            ),
            $nullable && ! $this->isNull($default) => sprintf(
                '%s::$%s is nullable, so it must default to null: a route function leaves a null query value out of the URL, and the server would read the default instead.',
                $class, $name,
            ),
            in_array('array', $types, true) && ! ($default instanceof Array_ && $default->items === []) => sprintf(
                '%s::$%s is a list, so it must default to []: a route function leaves an empty list out of the URL, and the server would read the default instead.',
                $class, $name,
            ),
            default => null,
        };

        if ($message === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message($message)
                ->identifier('strictly.queryDefault')
                ->line($line)
                ->tip('An absent query key means "no filter": give the property the default that means that (null for a nullable property, [] for a list).')
                ->build(),
        ];
    }

    /**
     * The lowercased names in a property's native type: `['null', 'string']` for `?string`.
     *
     * @return list<string>
     */
    private function types(Identifier|Name|ComplexType|null $type, Scope $scope): array
    {
        return match (true) {
            $type === null => ['mixed'],
            $type instanceof NullableType => ['null', ...$this->types($type->type, $scope)],
            $type instanceof UnionType => array_merge(...array_map(fn (Identifier|Name|IntersectionType $member): array => $this->types($member, $scope), $type->types)),
            $type instanceof Name => [strtolower($scope->resolveName($type))],
            $type instanceof Identifier => [$type->toLowerString()],
            default => [],
        };
    }

    private function isNull(Expr $expr): bool
    {
        return $expr instanceof ConstFetch && $expr->name->toLowerString() === 'null';
    }
}
