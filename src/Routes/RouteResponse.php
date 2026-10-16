<?php

namespace StrictlyLaravel\Routes;

use Closure;
use Illuminate\Routing\Route;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use ReflectionClass;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Actions\FindClassNameFqcnAction;
use Spatie\TypeScriptTransformer\Data\WritingContext;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use Spatie\TypeScriptTransformer\PhpNodes\PhpMethodNode;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TypeResolvers\DocTypeResolver;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnknown;
use Spatie\TypeScriptTransformer\Visitor\Visitor;
use StrictlyLaravel\Coverage\TemplateArgument;
use StrictlyLaravel\Inertia\Page;
use StrictlyLaravel\TypeScript\ClassTemplates;
use StrictlyLaravel\TypeScript\DataClassTransformer;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\MixedAsUnknown;

/**
 * The response of a JSON endpoint (Problem P-048): the declared return type of a controller action (or route closure)
 * that is a Data class and not a Page. It is what the route is branded with (WayfinderTemplates), so
 * `useRouteHttp(route, init)` types its result, what its recorded JSON responses are checked against
 * (ContractRecorder) and what makes the route a JSON endpoint (Contract Coverage, JsonEndpoints).
 *
 *     public function search(SearchQueryData $data): SearchResultsData
 *
 * Problem P-197: an action that returns a generic Data class writes its type arguments in its `@return`, which this
 * reads as the response when it names the native return class (`@return ListingData<IssueData>` on `: ListingData`).
 * The route is branded `ListingData<IssueData>`, its Contracts are checked against it, and Contract Coverage walks the
 * items as IssueData (TemplateArgument), as it does for a page prop of that type. A `@return` that adds no type
 * arguments changes nothing, and with none the route stays branded with the bare class, which `tsc` rejects (TS2314)
 * like PHPStan does (`missingType.generics`).
 *
 * An action of a controller the app doesn't own (Fortify's two-factor endpoints) declares no return type: its response
 * is the one config/strictly.php declares for it (VendorDeclarations, Problem P-050), a Data class or, for JSON that
 * isn't one object, a TypeScript type such as `'string[]'`. A nullable or union return type declares no response: the
 * route's result stays `unknown`.
 */
final readonly class RouteResponse
{
    /**
     * @param  class-string<Data>|string  $declared  the Data class, or a declared TypeScript type
     * @param  string  $typeScript  the response's TypeScript, from any file:
     *                              `import('@/generated').ListingData<import('@/generated').IssueData>`
     * @param  array<string, TemplateArgument>  $arguments  a generic Data class's templates, bound by the `@return`
     */
    private function __construct(
        public string $declared,
        public string $typeScript,
        public array $arguments = [],
    ) {}

    public static function ofAction(string $controller, string $method): ?self
    {
        $class = ltrim($controller, '\\');

        if ($class === 'Closure' || ! class_exists($class) || ! method_exists($class, $method)) {
            return null;
        }

        $declared = VendorDeclarations::response($class, $method);

        if ($declared !== null) {
            return new self($declared, is_subclass_of($declared, Data::class) ? WayfinderTemplates::generatedType($declared) : $declared);
        }

        return self::declaredData(new ReflectionMethod($class, $method));
    }

    /**
     * The response of the action a route runs, a controller method or a closure.
     */
    public static function ofRoute(Route $route): ?self
    {
        $uses = $route->getAction('uses');

        if ($uses instanceof Closure) {
            return self::declaredData(new ReflectionFunction($uses));
        }

        if (! is_string($uses)) {
            return null;
        }

        // An invokable controller's action name has no `@method`.
        $method = str_contains($route->getActionName(), '@') ? $route->getActionMethod() : '__invoke';

        return self::ofAction($route->getControllerClass() ?? '', $method);
    }

    /**
     * The response's Data class, or null when it is declared as a TypeScript type, whose shapes Contract Coverage can't
     * observe (Problem P-051).
     *
     * @return class-string<Data>|null
     */
    public function dataClass(): ?string
    {
        return ! VendorDeclarations::isTypeScript($this->declared) && is_subclass_of($this->declared, Data::class) ? $this->declared : null;
    }

    private static function declaredData(ReflectionFunctionAbstract $action): ?self
    {
        $type = $action->getReturnType();

        if (! $type instanceof ReflectionNamedType || $type->isBuiltin() || $type->allowsNull()) {
            return null;
        }

        $class = $type->getName();

        if (! is_subclass_of($class, Data::class) || is_subclass_of($class, Page::class)) {
            return null;
        }

        return ($action instanceof ReflectionMethod ? self::generic($action, $class) : null)
            ?? new self($class, WayfinderTemplates::generatedType($class));
    }

    /**
     * Problem P-197: the generic Data class an action returns, with the type arguments its `@return` gives it, read as
     * the generated types read a page prop's PHPDoc type. Null when the class has no templates, or the `@return` doesn't
     * write it with type arguments.
     *
     * @param  class-string<Data>  $class
     */
    private static function generic(ReflectionMethod $action, string $class): ?self
    {
        if (ClassTemplates::of(new PhpClassNode(new ReflectionClass($class))) === []) {
            return null;
        }

        $method = new PhpMethodNode($action);
        $return = (new DocTypeResolver)->method($method)?->returnType;
        $scope = $method->getDeclaringClass();

        if (! $return instanceof GenericTypeNode || $return->genericTypes === []
            || (new FindClassNameFqcnAction)->execute($scope, $return->type->name) !== $class) {
            return null;
        }

        return new self($class, self::typeScriptOf($return, $scope), TemplateArgument::bind($class, array_values($return->genericTypes), $scope));
    }

    /**
     * The TypeScript a PHPDoc type is generated as (DataClassTransformer's transpiler, with `mixed` as `unknown`), with
     * every class it names written as its generated type, so it can be read from any file.
     */
    private static function typeScriptOf(GenericTypeNode $type, PhpClassNode $scope): string
    {
        $references = [];
        $node = (new Visitor([new MixedAsUnknown]))
            ->before(function (TypeScriptReference $node) use (&$references): void {
                $reference = $node->reference;

                if ($reference instanceof ClassStringReference && class_exists($reference->classString)) {
                    $node->connect(new Transformed(new TypeScriptUnknown, $reference, []));
                    $references[$reference->getKey()] = WayfinderTemplates::generatedType($reference->classString);
                }
            }, [TypeScriptReference::class])
            ->execute(DataClassTransformer::phpDocTypes()->execute($type, $scope));

        return $node?->write(new WritingContext($references)) ?? 'unknown';
    }
}
