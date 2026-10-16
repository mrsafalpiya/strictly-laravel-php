<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassMethodNode;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\TypeUtils;
use PHPStan\Type\VerbosityLevel;

/**
 * Problem P-108: a controller action that answers JSON declares one Data class as its return type, which makes it a
 * JSON endpoint (RouteResponse): its route is branded with the class, so `useRouteHttp()` types the response, and the
 * responses its feature tests get are recorded as Contracts and counted by Contract Coverage. An action that declares
 * anything else Laravel sends as JSON escapes all of it without a word, so it is rejected:
 *
 * - a return type Laravel serialises to JSON with no Data class: `array` or `iterable` (`@return list<ProjectData>`
 *   included), a Collection, laravel-data's DataCollection, PaginatedDataCollection and CursorPaginatedDataCollection,
 *   a paginator, a model or an API resource (anything Arrayable, Jsonable or JsonSerializable that isn't a Data class:
 *   UntypedJson, which ClosureRouteRule reads closure routes with);
 * - a nullable Data class, or a union that holds one (`?ProjectData`, `ProjectData|RedirectResponse`), which
 *   RouteResponse doesn't read as a response (DataResponse, which ClosureRouteRule also reads closure routes with).
 *
 * Responses that aren't JSON data stay allowed: Page classes (on their own, nullable or in a union), redirects,
 * `Response`, downloads and `void`. So does a `JsonResponse`, the explicit way to answer JSON that stays untyped
 * (the route's response is `unknown`, which the client must narrow before it reads it, P-048). The types are the
 * native ones RouteResponse reads, not the PHPDoc.
 *
 * @implements Rule<InClassMethodNode>
 */
final readonly class JsonResponseRule implements Rule
{
    public function __construct(
        private ReflectionProvider $reflection,
    ) {}

    public function getNodeType(): string
    {
        return InClassMethodNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $controller = ControllerAction::of($node->getOriginalNode(), $scope);

        if ($controller === null) {
            return [];
        }

        $variants = $node->getMethodReflection()->getVariants();

        if (count($variants) !== 1) {
            return [];
        }

        $type = $variants[0]->getNativeReturnType();
        $members = TypeUtils::flattenTypes($type);
        $action = sprintf('%s::%s()', $controller->getName(), $node->getOriginalNode()->name->toString());
        $returns = $type->describe(VerbosityLevel::typeOnly());
        $consequence = 'useRouteHttp() types the route\'s response unknown, and no Contract checks it.';

        foreach ($members as $member) {
            if (UntypedJson::is($member, $this->reflection)) {
                return [$this->error(
                    "{$action} returns {$returns}, which Laravel sends as JSON that nothing types: {$consequence}",
                    'Return one Data class that holds the value instead, such as RecentProjectsData with a list<ProjectData> property: it types the route\'s response, and every response a feature test gets is checked against it. See the strictly-add-json-endpoint skill.',
                )];
            }
        }

        if (count($members) > 1 && array_any($members, DataResponse::is(...))) {
            return [$this->error(
                "{$action} returns {$returns}, a nullable or union type, which declares no response: {$consequence}",
                'Return one Data class on every path: put a value that may be missing in a nullable property (ProjectLookupData with a ?ProjectData property), and refuse a request with abort() or a validation error. See the strictly-add-json-endpoint skill.',
            )];
        }

        return [];
    }

    private function error(string $message, string $tip): IdentifierRuleError
    {
        return RuleErrorBuilder::message($message)->identifier('strictly.jsonResponse')->tip($tip)->build();
    }
}
