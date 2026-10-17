<?php

namespace StrictlyLaravel\Workarounds\Laravel;

use Illuminate\Support\Collection;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Analyser\Scope;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Reflection\ParametersAcceptor;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\DynamicMethodReturnTypeExtension;
use PHPStan\Type\Generic\GenericObjectType;
use PHPStan\Type\IntegerRangeType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\UnionType;

/**
 * Problem P-207 (docs/problems.md). Upstream: no upstream issue filed (Laravel declares `countBy()` as returning
 * `static<array-key, int>`).
 *
 * `countBy()` keys its counts by what its callback returns, or by the items themselves without one, an enum case by its
 * value (`enum_value()`). Laravel's declaration drops that type, so `$issues->countBy(fn (Issue $issue) =>
 * $issue->status)->all()` can't fill an `array<value-of<IssueStatus>, int>` map (P-033), which then gets counted by
 * hand. This types the result as `Collection<K, int<1, max>>`, K being the callback's return type (or the items' type),
 * each enum in it as its cases' values. A field name (`countBy('status')`), or a key type that isn't an int, a string
 * or an enum, keeps Laravel's own type. Delete this class once upstream types the keys.
 */
final class CollectionCountByKeys implements DynamicMethodReturnTypeExtension
{
    public function getClass(): string
    {
        return Collection::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection): bool
    {
        return $methodReflection->getName() === 'countBy';
    }

    public function getTypeFromMethodCall(MethodReflection $methodReflection, MethodCall $methodCall, Scope $scope): ?Type
    {
        $arguments = $methodCall->getArgs();

        if ($arguments === []) {
            $grouped = $scope->getType($methodCall->var)->getIterableValueType();
        } else {
            $callback = $scope->getType($arguments[0]->value);

            // A string is a field name to Laravel's valueRetriever(), even one that names a function.
            if ($callback->isString()->yes() || $callback->isNull()->yes() || ! $callback->isCallable()->yes()) {
                return null;
            }

            $grouped = TypeCombinator::union(...array_map(
                fn (ParametersAcceptor $acceptor): Type => $acceptor->getReturnType(),
                $callback->getCallableParametersAcceptors($scope),
            ));
        }

        $keys = $this->keys($grouped);

        return $keys === null ? null : new GenericObjectType(Collection::class, [$keys, IntegerRangeType::fromInterval(1, null)]);
    }

    /**
     * The keys the counts have for a callback that returns `$type`: an enum case is its value (its name for a pure
     * enum), an int or a string is itself; anything else gives no key type this can tell.
     */
    private function keys(Type $type): ?Type
    {
        $keys = [];

        foreach ($type instanceof UnionType ? $type->getTypes() : [$type] as $member) {
            $cases = $member->getEnumCases();

            if ($cases !== []) {
                foreach ($cases as $case) {
                    $keys[] = $case->getBackingValueType() ?? new ConstantStringType($case->getEnumCaseName());
                }
            } elseif ($member->isInteger()->yes() || $member->isString()->yes()) {
                $keys[] = $member;
            } else {
                return null;
            }
        }

        return $keys === [] ? null : TypeCombinator::union(...$keys);
    }
}
