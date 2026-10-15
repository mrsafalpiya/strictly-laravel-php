<?php

namespace StrictlyLaravel\Coverage;

use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\Paginator;
use Inertia\MergeProp;
use Inertia\OnceProp;
use Inertia\ScrollProp;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Spatie\LaravelData\CursorPaginatedDataCollection;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\PaginatedDataCollection;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\TypeScriptTransformer\Actions\FindClassNameFqcnAction;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use StrictlyLaravel\TypeScript\PropertyAnnotation;

/**
 * What a page prop wrapped in one of Inertia's always-sent prop classes holds (Problem P-133). laravel-data describes
 * `public ScrollProp $activity` as an object it knows nothing about, while the prop's PHPDoc type
 * (`ScrollProp<CursorPaginatedDataCollection<array-key, ActivityData>>`) is what its TypeScript type is generated from
 * (Workarounds\TypeScriptTransformer\InertiaPropWrappers). This reads the Data class inside it, so Contract Coverage
 * walks the prop like the collection or Data object Inertia sends.
 */
final readonly class WrappedProp
{
    /** @var list<class-string> */
    private const array WRAPPERS = [ScrollProp::class, MergeProp::class, OnceProp::class];

    /**
     * @param  class-string<Data>  $dataClass
     * @param  'paginated'|'list'|null  $collection  how the Data objects are sent: in a paginated collection's `data`, as
     *                                               a list, or one object (null)
     */
    private function __construct(
        public string $dataClass,
        public ?string $collection,
    ) {}

    public static function of(DataProperty $property): ?self
    {
        $wrapped = array_intersect(array_keys($property->type->type->getAcceptedTypes()), self::WRAPPERS) !== [];

        if (! $wrapped || ! class_exists($property->className)) {
            return null;
        }

        $annotation = PropertyAnnotation::of($property->className, $property->name);
        $type = $annotation?->type;

        if ($annotation === null || ! $type instanceof GenericTypeNode || count($type->genericTypes) !== 1) {
            return null;
        }

        return self::inner($type->genericTypes[0], $annotation->scope);
    }

    private static function inner(TypeNode $type, PhpClassNode $scope): ?self
    {
        if ($type instanceof ArrayTypeNode) {
            $item = self::dataClass($type->type, $scope);

            return $item === null ? null : new self($item, 'list');
        }

        if ($type instanceof IdentifierTypeNode) {
            $class = self::dataClass($type, $scope);

            return $class === null ? null : new self($class, null);
        }

        if (! $type instanceof GenericTypeNode || $type->genericTypes === []) {
            return null;
        }

        $item = self::dataClass($type->genericTypes[count($type->genericTypes) - 1], $scope);

        if ($item === null) {
            return null;
        }

        $collection = self::className($type->type, $scope);
        $paginated = $collection !== null && array_filter(
            [PaginatedDataCollection::class, CursorPaginatedDataCollection::class, Paginator::class, CursorPaginator::class],
            fn (string $paginator): bool => is_a($collection, $paginator, true),
        ) !== [];

        return new self($item, $paginated ? 'paginated' : 'list');
    }

    /**
     * @return class-string<Data>|null
     */
    private static function dataClass(TypeNode $type, PhpClassNode $scope): ?string
    {
        $class = $type instanceof IdentifierTypeNode ? self::className($type, $scope) : null;

        return $class !== null && is_subclass_of($class, Data::class) ? $class : null;
    }

    /**
     * @return class-string|null
     */
    private static function className(IdentifierTypeNode $type, PhpClassNode $scope): ?string
    {
        $class = (new FindClassNameFqcnAction)->execute($scope, $type->name);

        return $class !== null && (class_exists($class) || interface_exists($class)) ? $class : null;
    }
}
