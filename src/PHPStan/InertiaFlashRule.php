<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Problem P-047: Inertia flash data is written only through the app's flash Data class (a subclass of
 * StrictlyLaravel\Inertia\Flash), whose type `strictly:generate` writes into `InertiaConfig['flashDataType']`. Every
 * other way to flash sends keys and values no generated type describes, so it is rejected: `Inertia::flash()`,
 * `ResponseFactory::flash()` (`inertia()->flash()`) and `Response::flash()` on a rendered page.
 *
 * @implements Rule<CallLike>
 */
final class InertiaFlashRule implements Rule
{
    private const string INERTIA_FACADE = 'Inertia\Inertia';

    /** The classes whose flash() writes Inertia flash data. */
    private const array FLASHING_CLASSES = ['Inertia\ResponseFactory', 'Inertia\Response'];

    public function getNodeType(): string
    {
        return CallLike::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $call = match (true) {
            $node instanceof StaticCall => $this->staticCall($node, $scope),
            $node instanceof MethodCall => $this->methodCall($node, $scope),
            default => null,
        };

        if ($call === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message("{$call} flashes data that has no generated type.")
                ->identifier('strictly.inertiaFlash')
                ->tip('Flash through the app\'s flash Data class instead (App\Data\FlashData, which extends StrictlyLaravel\Inertia\Flash): `(new FlashData(...))->flash()`.')
                ->build(),
        ];
    }

    private function staticCall(StaticCall $node, Scope $scope): ?string
    {
        return $node->class instanceof Name && $node->name instanceof Identifier
            && $scope->resolveName($node->class) === self::INERTIA_FACADE && $node->name->toLowerString() === 'flash'
            ? 'Inertia::flash()'
            : null;
    }

    private function methodCall(MethodCall $node, Scope $scope): ?string
    {
        if (! $node->name instanceof Identifier || $node->name->toLowerString() !== 'flash') {
            return null;
        }

        $classes = array_intersect(self::FLASHING_CLASSES, $scope->getType($node->var)->getObjectClassNames());

        return $classes === [] ? null : reset($classes).'::flash()';
    }
}
