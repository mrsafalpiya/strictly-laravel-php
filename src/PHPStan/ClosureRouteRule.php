<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node;
use PhpParser\Node\ComplexType;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\NullableType;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\ClosureType;
use PHPStan\Type\NeverType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\VerbosityLevel;
use Spatie\LaravelData\Contracts\BaseData;
use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\Data\QueryData;
use StrictlyLaravel\Inertia\Page;

/**
 * A closure route takes no input class, query class, other Data class or FormRequest, and returns no Data class but a
 * Page (Problems P-097, P-109, P-151, P-167). Wayfinder types a route function only from the controller action it runs,
 * never from a closure, so such a route function has no input, query or response brand: `visit(store())` sends no
 * payload and `useRouteForm(store(), …)` refuses it, `index({ query })` takes no keys, and `useRouteHttp(stats())` types
 * its result `unknown`. Reading the payload or the query string in a closure route is InputReadRule's and
 * QueryReadRule's.
 *
 * A route a test registers is left alone (RouteClosure).
 *
 * @implements Rule<CallLike>
 */
final readonly class ClosureRouteRule implements Rule
{
    private const string FORM_REQUEST = 'Illuminate\Foundation\Http\FormRequest';

    public function __construct(
        private ReflectionProvider $reflection,
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
        $route = RouteClosure::of($node, $scope);

        if ($route === null) {
            return [];
        }

        $tip = 'Move the route to a controller action (a public method of a class in App\Http\Controllers): Wayfinder brands a route function with the input class, query class and response Data class of the controller action it runs.';
        $errors = [];

        foreach ($route->closure->params as $param) {
            $class = $this->classOf($param->type, $scope);

            if ($class === null) {
                continue;
            }

            [$kind, $consequence] = match (true) {
                $class->isSubclassOf(InputData::class) => ['an input class', 'payload'],
                $class->isSubclassOf(QueryData::class) => ['a query class', 'query'],
                $class->implementsInterface(BaseData::class) => ['a Data class', 'payload'],
                $class->isSubclassOf(self::FORM_REQUEST) => ['a FormRequest', 'payload'],
                default => [null, null],
            };

            if ($kind === null) {
                continue;
            }

            $errors[] = RuleErrorBuilder::message(sprintf(
                $consequence === 'query'
                    ? "The closure route %s takes %s, %s, but Wayfinder types a route function's query option only from a controller action: no route function can send its keys."
                    : "The closure route %s takes %s, %s, but Wayfinder types a route function's payload only from a controller action: no typed form can send it.",
                $route->shown, $class->getName(), $kind,
            ))
                ->identifier('strictly.closureRoute')
                ->line($param->getStartLine())
                ->tip($kind === 'a FormRequest'
                    ? sprintf(
                        'Move the route to a controller action that takes an input class instead (a final Data class extending %s, with the FormRequest\'s rules() and authorize()): Wayfinder brands a route function with the input class of the controller action it runs.',
                        InputData::class,
                    )
                    : $tip)
                ->build();
        }

        $response = $this->response($route, $scope);

        if ($response !== null) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                "The closure route %s returns %s, but Wayfinder types a route function's response only from a controller action: useRouteHttp() types its result unknown.",
                $route->shown, $response,
            ))
                ->identifier('strictly.closureRoute')
                ->line($route->closure->returnType?->getStartLine() ?? $route->closure->getStartLine())
                ->tip($tip)
                ->build();
        }

        return $errors;
    }

    /**
     * The Data class a closure returns, declared or inferred (`fn () => new StatsData(…)`), null or not; null when it
     * returns no Data class, or a Page, which a closure route may render.
     */
    private function response(RouteClosure $route, Scope $scope): ?string
    {
        // PHPStan types an arrow function's result by its expression, whatever return type it declares.
        if ($route->closure->returnType !== null) {
            $response = $scope->getFunctionType($route->closure->returnType, false, false);
        } else {
            $closure = $scope->getType($route->closure);
            $response = $closure instanceof ClosureType ? $closure->getReturnType() : null;
        }

        // `fn () => null` returns no Data class.
        $response = $response === null ? null : TypeCombinator::removeNull($response);

        if ($response === null || $response instanceof NeverType) {
            return null;
        }

        if (! (new ObjectType(BaseData::class))->isSuperTypeOf($response)->yes() || ! (new ObjectType(Page::class))->isSuperTypeOf($response)->no()) {
            return null;
        }

        return $response->describe(VerbosityLevel::typeOnly());
    }

    /** The class a parameter type names, nullable or not; null for any other type. */
    private function classOf(Identifier|Name|ComplexType|null $type, Scope $scope): ?ClassReflection
    {
        $type = $type instanceof NullableType ? $type->type : $type;

        if (! $type instanceof Name || ! $this->reflection->hasClass($scope->resolveName($type))) {
            return null;
        }

        return $this->reflection->getClass($scope->resolveName($type));
    }
}
