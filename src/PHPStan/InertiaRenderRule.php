<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\CallLike;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;

/**
 * Problems P-009 and P-011: Inertia pages are rendered only by returning a Page class, whose constructor defines the
 * props that `PageProps<'component'>` is generated from. Every other way to render a page sends props no generated
 * type describes, so it is rejected: `Inertia::render()` (with an array or a Data object, or with no props at all,
 * which leaves the page without a Page class), the `inertia()` helper with a component, `ResponseFactory::render()`
 * and `Route::inertia()`. Inertia's other APIs (`inertia()->share()`, …) are untouched; flashing has its own rule
 * (InertiaFlashRule).
 *
 * @implements Rule<CallLike>
 */
final class InertiaRenderRule implements Rule
{
    private const string INERTIA_FACADE = 'Inertia\Inertia';

    private const string RESPONSE_FACTORY = 'Inertia\ResponseFactory';

    private const string ROUTE_FACADE = 'Illuminate\Support\Facades\Route';

    private const string ROUTER = 'Illuminate\Routing\Router';

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
            $node instanceof FuncCall => $this->functionCall($node),
            $node instanceof MethodCall => $this->methodCall($node, $scope),
            default => null,
        };

        if ($call === null) {
            return [];
        }

        return [
            RuleErrorBuilder::message("{$call} renders a page whose props have no generated type.")
                ->identifier('strictly.inertiaRender')
                ->tip('Return a Page class instead, whose constructor defines the props: `php artisan make:page <component>` creates it and its page component.')
                ->build(),
        ];
    }

    private function staticCall(StaticCall $node, Scope $scope): ?string
    {
        if (! $node->class instanceof Name || ! $node->name instanceof Identifier) {
            return null;
        }

        return match ([$scope->resolveName($node->class), $node->name->toLowerString()]) {
            [self::INERTIA_FACADE, 'render'] => 'Inertia::render()',
            [self::ROUTE_FACADE, 'inertia'] => 'Route::inertia()',
            default => null,
        };
    }

    /** `inertia()` with no arguments returns the ResponseFactory; a render() on it is a method call. */
    private function functionCall(FuncCall $node): ?string
    {
        return $node->name instanceof Name && $node->name->toLowerString() === 'inertia' && $node->getArgs() !== []
            ? 'inertia()'
            : null;
    }

    private function methodCall(MethodCall $node, Scope $scope): ?string
    {
        if (! $node->name instanceof Identifier) {
            return null;
        }

        $class = match ($node->name->toLowerString()) {
            'render' => self::RESPONSE_FACTORY,
            'inertia' => self::ROUTER,
            default => null,
        };

        return $class !== null && in_array($class, $scope->getType($node->var)->getObjectClassNames(), true)
            ? "{$class}::{$node->name->toString()}()"
            : null;
    }
}
