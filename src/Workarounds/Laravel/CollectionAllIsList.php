<?php

namespace StrictlyLaravel\Workarounds\Laravel;

use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\Accessory\AccessoryArrayListType;
use PHPStan\Type\ArrayType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;

/**
 * Problem P-206 (docs/problems.md). Upstream: no upstream issue filed (Laravel, Larastan).
 *
 * A generic `Collection<int, TValue>` can't say its keys run from 0 without a gap, so `all()` is always
 * `array<int, TValue>`, and a Data property declared `list<X>` (DataListRule) rejects even a collection that is one.
 * This types `all()` as `list<TValue>` when the chain it ends proves the keys are 0, 1, 2…: it starts from a list
 * (`values()`, which renumbers; a query's `get()`; `Model::all()`; `collect()` of a list) and goes through only calls
 * that keep each key (`map()`, `mapInto()`, `each()`, `tap()`). Any other call, such as `filter()`, `unique()`,
 * `sortBy()` or `keyBy()`, keeps Laravel's type, since it can leave a gap or reorder the keys. A relation property
 * (`$issue->labels`) isn't proven one either, since the relation can be set to any collection; end its chain with
 * `->values()->all()`. Delete this class once upstream types such chains as lists.
 */
final class CollectionAllIsList implements DynamicMethodReturnTypeExtension
{
    /** Calls on a collection that give each item the key it had. */
    private const array KEEP_KEYS = ['map', 'mapinto', 'each', 'tap'];

    public function getClass(): string
    {
        return Collection::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'all';
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        if (! $this->isList($methodCall->var, $scope)) {
            return null;
        }

        return TypeCombinator::intersect(
            new ArrayType(IntegerRangeType::fromInterval(0, null), $scope->getType($methodCall->var)->getIterableValueType()),
            new AccessoryArrayListType,
        );
    }

    /** Whether a collection expression's keys are proven to be 0, 1, 2… */
    private function isList(Expr $collection, Scope $scope): bool
    {
        while ($collection instanceof MethodCall && $collection->name instanceof Identifier
            && in_array($collection->name->toLowerString(), self::KEEP_KEYS, true)) {
            $collection = $collection->var;
        }

        if ($collection instanceof MethodCall && $collection->name instanceof Identifier) {
            $method = $collection->name->toLowerString();

            return $method === 'values' || ($method === 'get' && $this->isQuery($scope->getType($collection->var)));
        }

        if ($collection instanceof StaticCall && $collection->name instanceof Identifier
            && $collection->name->toLowerString() === 'all' && $collection->class instanceof Name) {
            return (new ObjectType(Model::class))->isSuperTypeOf(new ObjectType($scope->resolveName($collection->class)))->yes();
        }

        return $collection instanceof FuncCall && $collection->name instanceof Name
            && $collection->name->toLowerString() === 'collect' && count($collection->getArgs()) === 1
            && $scope->getType($collection->getArgs()[0]->value)->isList()->yes();
    }

    /** An Eloquent or query builder, or a relation, whose `get()` returns its rows as a list. */
    private function isQuery(Type $type): bool
    {
        foreach ([EloquentBuilder::class, Relation::class, QueryBuilder::class] as $query) {
            if ((new ObjectType($query))->isSuperTypeOf($type)->yes()) {
                return true;
            }
        }

        return false;
    }
}
