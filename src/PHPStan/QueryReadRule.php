<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
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
        if (RequestRead::of($node, $scope)?->accessor !== 'query') {
            return [];
        }

        $controller = ControllerAction::enclosing($scope);

        if ($controller === null) {
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
}
