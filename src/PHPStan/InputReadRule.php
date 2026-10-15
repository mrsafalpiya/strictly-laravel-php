<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\Data\QueryData;

/**
 * Problem P-112: a controller method that takes no input or query class reads no input from the request
 * (`$request->input('q')`, `$request->string('name')`, `$request->all()`, `$request->validate([...])`, `$request->name`,
 * `$request['name']`, `Request::input('q')`, `request('q')`). No type describes such a payload: a typed form sends only
 * the fields of its action's input class, so it has none for these keys, and the input Contracts only check a form
 * endpoint's reads.
 *
 * A method that takes an input or query class may still read the request: the Contract its requests record checks
 * every key it reads against that class (P-024). The query string itself is QueryReadRule's (P-106). Reading what isn't
 * the payload (`user()`, `route()`, `header()`, `ip()`, `session()`, `cookie()`) is fine.
 *
 * A closure route reads no input either (Problem P-167): Wayfinder types no route function from a closure, so no typed
 * form can send it any (RouteClosure).
 *
 * @implements Rule<Expr>
 */
final readonly class InputReadRule implements Rule
{
    /**
     * The request's accessors that read its input (`InteractsWithInput`, `InteractsWithData`, `Request`, the
     * `validate()` macros and FormRequest's own), lowercased; `->` is a key read as a property, `[]` an array access.
     */
    private const array INPUT = [
        '->', '[]', 'request', 'files',
        'all', 'allfiles', 'anyfilled', 'array', 'boolean', 'clamp', 'collect', 'date', 'enum', 'enums', 'except',
        'exists', 'file', 'filled', 'float', 'fluent', 'get', 'getpayload', 'has', 'hasany', 'hasfile', 'image', 'input',
        'integer', 'interval', 'isnotfilled', 'json', 'keys', 'mergeifmissing', 'missing', 'offsetexists', 'offsetget',
        'only', 'post', 'safe', 'str', 'string', 'toarray', 'validate', 'validated', 'validatewithbag', 'validationdata',
        'whenenum', 'whenfilled', 'whenhas', 'whenmissing', '__get', '__isset',
    ];

    public function getNodeType(): string
    {
        return Expr::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $read = RequestRead::of($node, $scope);

        if ($read === null || ! in_array($read->accessor, self::INPUT, true)) {
            return [];
        }

        $controller = ControllerAction::enclosing($scope);

        if ($controller === null) {
            return RouteClosure::encloses($scope) ? [$this->closureRouteError($read)] : [];
        }

        if ($this->takesRequestData($scope)) {
            return [];
        }

        return [
            RuleErrorBuilder::message(sprintf(
                '%s::%s() reads input from the request with %s, where nothing types it: a typed form sends only the fields of its action\'s input class.',
                $controller->getName(), $scope->getFunctionName() ?? '{closure}', $read->shown,
            ))
                ->identifier('strictly.inputRead')
                ->tip(sprintf(
                    'Take an input class on the action (a final Data class extending %s) and read the field from it: it types the route\'s form and its validation errors. A GET\'s query string goes in a query class (extending %s) instead.',
                    InputData::class, QueryData::class,
                ))
                ->build(),
        ];
    }

    /** A closure route's read (Problem P-167): its route function has no input type at all (ClosureRouteRule). */
    private function closureRouteError(RequestRead $read): IdentifierRuleError
    {
        return RuleErrorBuilder::message(sprintf(
            'A closure route reads input from the request with %s, where nothing types it: a typed form sends only the fields of a controller action\'s input class.',
            $read->shown,
        ))
            ->identifier('strictly.inputRead')
            ->tip(sprintf(
                'Move the route to a controller action that takes an input class (a final Data class extending %s) and read the field from it: Wayfinder brands a route function with the input class of the controller action it runs, never with a closure\'s.',
                InputData::class,
            ))
            ->build();
    }

    /** Whether the method the read is in takes an input or query class, whose Contract checks the keys it reads. */
    private function takesRequestData(Scope $scope): bool
    {
        $function = $scope->getFunction();

        if ($function === null) {
            return false;
        }

        foreach ($function->getOnlyVariant()->getParameters() as $parameter) {
            $type = TypeCombinator::removeNull($parameter->getType());

            foreach (InputDataRule::REQUEST_DATA as $base) {
                if ((new ObjectType($base))->isSuperTypeOf($type)->yes()) {
                    return true;
                }
            }
        }

        return false;
    }
}
