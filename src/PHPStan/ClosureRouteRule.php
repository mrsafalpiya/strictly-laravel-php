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
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeUtils;
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
 * Nor does a closure route return anything else Laravel answers as JSON that no Data class types (UntypedJson, as
 * JsonResponseRule reads controller actions with, P-179): an array, a Collection, a model, an API resource. Pages,
 * redirects, `Response`, views, strings and a `JsonResponse` (JSON built by hand, which stays untyped: P-048) stay
 * allowed.
 *
 * A route a test registers is left alone (RouteClosure).
 *
 * @implements Rule<CallLike>
 */
final readonly class ClosureRouteRule implements Rule
{
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
                $class->isSubclassOf(FormRequestRule::FORM_REQUEST) => ['a FormRequest', 'payload'],
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
        $line = $route->closure->returnType?->getStartLine() ?? $route->closure->getStartLine();

        if ($response === null) {
            return $errors;
        }

        $returns = $response->describe(VerbosityLevel::typeOnly());

        if (array_any(TypeUtils::flattenTypes($response), fn (Type $member): bool => UntypedJson::is($member, $this->reflection))) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                "The closure route %s returns %s, which Laravel sends as JSON that nothing types: Wayfinder types a route function's response only from a controller action that returns a Data class, so useRouteHttp() types its result unknown, and no Contract checks it.",
                $route->shown, $returns,
            ))
                ->identifier('strictly.closureRoute')
                ->line($line)
                ->tip('Move the route to a controller action that returns one Data class holding the value, such as RecentProjectsData with a list<ProjectData> property: it types the route\'s response, and every response a feature test gets is checked against it. See the strictly-add-json-endpoint skill.')
                ->build();
        } elseif ((new ObjectType(BaseData::class))->isSuperTypeOf($response)->yes() && (new ObjectType(Page::class))->isSuperTypeOf($response)->no()) {
            $errors[] = RuleErrorBuilder::message(sprintf(
                "The closure route %s returns %s, but Wayfinder types a route function's response only from a controller action: useRouteHttp() types its result unknown.",
                $route->shown, $returns,
            ))
                ->identifier('strictly.closureRoute')
                ->line($line)
                ->tip($tip)
                ->build();
        }

        return $errors;
    }

    /**
     * What a closure returns, declared or inferred (`fn () => Project::all()`), without null (`fn () => null` returns
     * nothing); null when it never returns.
     */
    private function response(RouteClosure $route, Scope $scope): ?Type
    {
        // PHPStan types an arrow function's result by its expression, whatever return type it declares.
        if ($route->closure->returnType !== null) {
            $response = $scope->getFunctionType($route->closure->returnType, false, false);
        } else {
            $closure = $scope->getType($route->closure);
            $response = $closure instanceof ClosureType ? $closure->getReturnType() : null;
        }

        $response = $response === null ? null : TypeCombinator::removeNull($response);

        return $response === null || $response instanceof NeverType ? null : $response;
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
