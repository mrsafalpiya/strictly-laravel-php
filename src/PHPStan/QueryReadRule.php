<?php

namespace StrictlyLaravel\PHPStan;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Request as RequestFacade;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use StrictlyLaravel\Data\QueryData;

/**
 * Problem P-106: a controller reads the query string only through its action's query class (a QueryData subclass),
 * never from the request: `$request->query('tab')`, `$request->query->get('tab')`, `request()->query('tab')` or
 * `Request::query('tab')`. A route function sends only the keys of its action's query class (none when it takes none),
 * so such a key is one no typed link sets, and nothing checks its value.
 *
 * Framework code that reads its own key (the paginators' `page` and `cursor`) isn't in a controller, so it isn't
 * affected.
 *
 * @implements Rule<Expr>
 */
final readonly class QueryReadRule implements Rule
{
    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        if (! $this->readsQuery($node, $scope)) {
            return [];
        }

        $controller = $scope->getClassReflection();

        if ($controller === null || ! ControllerAction::isController($controller)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s::%s() reads the query string from the request, where nothing types it: a route function sends only the keys of its action\'s query class.',
                $controller->getName(), $scope->getFunctionName() ?? '{closure}',
            ))
                ->identifier('strictly.queryRead')
                ->tip(sprintf(
                    'Take a query class on the action (a final Data class extending %s) and read the key from it: it types the route function\'s query option and falls back to its default on an invalid value.',
                    QueryData::class,
                ))
                ->build(),
        ];
    }

    private function readsQuery(Node $node, Scope $scope): bool
    {
        return match (true) {
            // PHPStan also analyses `$request?->query()` as a MethodCall.
            $node instanceof MethodCall, $node instanceof PropertyFetch => $this->named($node->name, 'query')
                && (new ObjectType(Request::class))->isSuperTypeOf($scope->getType($node->var))->yes(),
            $node instanceof StaticCall => $this->named($node->name, 'query')
                && $node->class instanceof Name
                && $scope->resolveName($node->class) === RequestFacade::class,
            default => false,
        };
    }

    private function named(Identifier|Expr $name, string $expected): bool
    {
        return $name instanceof Identifier && $name->toLowerString() === $expected;
    }
}
