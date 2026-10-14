<?php

namespace StrictlyLaravel\Coverage;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Enums\DataTypeKind;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\LaravelData\Support\DataProperty;
use stdClass;

/**
 * Compares a Data class with every value the feature tests recorded for it, at any depth, and finds the states its
 * type permits that no Contract showed (Problem P-066). `tsc` checked a Contract only for the values in it, so each
 * of these is a shape no check ever saw:
 *
 * - a nullable property only ever `null`;
 * - an Optional (or lazy) property never sent;
 * - a list or map only ever empty (so its item type was never checked);
 * - a union of Data classes never sent as one of them;
 * - a union of scalars (`int|string`) never sent as one of its members.
 *
 * Shapes are kept per Data class property, where the type lives and is fixed: a state counts as seen once any value
 * anywhere showed it. So a Data class used on two pages needs its non-null value on just one of them.
 *
 * Every dimension that records a Data class's values (page props, and later JSON responses, flash, broadcasts) feeds
 * them in through observe(), with the Origin a suggested test is built from.
 */
final class ShapeCoverage
{
    /**
     * @var array<string, array{what: string, at: list<string>, fix: string, seen: bool}>
     */
    private array $shapes = [];

    public function __construct(
        private readonly DataConfig $config,
    ) {}

    /**
     * Every value recorded for one Data class (decoded with objects as stdClass), from one origin.
     *
     * @param  class-string<Data>  $class
     * @param  list<mixed>  $values
     */
    public function observe(string $class, array $values, Origin $origin): void
    {
        $this->walk($class, $values, $origin, '');
    }

    /**
     * The states no recorded value showed, sorted.
     *
     * @return list<Gap>
     */
    public function gaps(): array
    {
        $gaps = [];

        foreach ($this->shapes as $key => $shape) {
            if (! $shape['seen']) {
                $gaps["{$shape['what']}\0{$key}"] = new Gap($shape['what'], $shape['at'], $shape['fix'], unexercised: false);
            }
        }

        ksort($gaps);

        return array_values($gaps);
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $values
     */
    private function walk(string $class, array $values, Origin $origin, string $prefix): void
    {
        $objects = array_values(array_filter($values, fn (mixed $value): bool => $value instanceof stdClass));

        if ($objects === []) {
            return;
        }

        foreach ($this->config->getDataClass($class)->properties as $property) {
            if ($property->hidden) {
                continue;
            }

            $name = $property->outputMappedName ?? $property->name;
            $path = $prefix === '' ? $name : "{$prefix}.{$name}";
            $present = [];

            foreach ($objects as $object) {
                if (property_exists($object, $name)) {
                    $present[] = $object->{$name};
                }
            }

            if ($property->type->isOptional || $property->type->lazyType !== null) {
                $this->shape($class, $property, 'is only ever absent', $origin, $path, 'sent', seen: $present !== []);
            }

            $nonNull = array_values(array_filter($present, fn (mixed $value): bool => $value !== null));

            if ($property->type->isNullable && $present !== []) {
                $this->shape($class, $property, 'is only ever null', $origin, $path, 'not null', seen: $nonNull !== []);
            }

            if ($nonNull !== []) {
                $this->scalarMembers($class, $property, $nonNull, $origin, $path);
                $this->walkValue($class, $property, $nonNull, $origin, $path);
            }
        }
    }

    /**
     * @param  class-string  $owner
     * @param  list<mixed>  $values  the property's non-null values
     */
    private function walkValue(string $owner, DataProperty $property, array $values, Origin $origin, string $path): void
    {
        $type = $property->type;

        if ($type->kind === DataTypeKind::DataObject) {
            $variants = array_values(array_filter(
                array_keys($type->type->getAcceptedTypes()),
                fn (string $accepted): bool => is_subclass_of($accepted, Data::class),
            ));

            if (count($variants) <= 1) {
                if ($type->dataClass !== null && class_exists($type->dataClass)) {
                    $this->walk($type->dataClass, $values, $origin, $path);
                }

                return;
            }

            foreach ($variants as $variant) {
                $matching = array_values(array_filter($values, fn (mixed $value): bool => $this->isVariant($variant, $value)));
                $variantName = class_basename($variant);

                $this->shape($owner, $property, "is never seen as {$variantName}", $origin, $path, "as {$variantName}", seen: $matching !== []);
                $this->walk($variant, $matching, $origin, $path);
            }

            return;
        }

        $items = match (true) {
            in_array($type->kind, [
                DataTypeKind::DataPaginatedCollection, DataTypeKind::DataCursorPaginatedCollection,
                DataTypeKind::DataPaginator, DataTypeKind::DataCursorPaginator,
            ], true) => $this->items(array_map(fn (mixed $value): mixed => $value instanceof stdClass ? ($value->data ?? []) : [], $values)),
            $type->kind->isDataCollectable(), $type->kind === DataTypeKind::Array, $type->kind === DataTypeKind::Enumerable => $this->items($values),
            default => null,
        };

        if ($items === null) {
            return;
        }

        $this->shape($owner, $property, 'is only ever empty', $origin, $path, "at least one item in {$path}", seen: $items !== [], fixIsComplete: true);

        if ($type->kind->isDataCollectable() && $type->dataClass !== null && class_exists($type->dataClass)) {
            $this->walk($type->dataClass, $items, $origin, "{$path}[]");
        }
    }

    /**
     * A union of scalars (`int|string`) is only proven for the members a test produced, so each must be seen. `int`
     * and `float` are both a TypeScript `number`, so they count as one member.
     *
     * @param  class-string  $class
     * @param  list<mixed>  $values  the property's non-null values
     */
    private function scalarMembers(string $class, DataProperty $property, array $values, Origin $origin, string $path): void
    {
        $accepted = array_keys($property->type->type->getAcceptedTypes());
        $members = [];

        foreach (['int' => 'number', 'float' => 'number', 'string' => 'string', 'bool' => 'boolean'] as $php => $typeScript) {
            if (in_array($php, $accepted, true)) {
                $members[$typeScript] ??= $php;
            }
        }

        if (count($members) < 2) {
            return;
        }

        $seen = array_map(fn (mixed $value): string => match (true) {
            is_int($value), is_float($value) => 'number',
            is_bool($value) => 'boolean',
            default => 'string',
        }, $values);

        foreach ($members as $typeScript => $php) {
            $this->shape($class, $property, "is never seen as {$php}", $origin, $path, "as {$php}", seen: in_array($typeScript, $seen, true));
        }
    }

    /**
     * Whether a value is the given Data class's variant of a union: it sends every property the class always sends,
     * and nothing the class doesn't have.
     *
     * @param  class-string  $variant
     */
    private function isVariant(string $variant, mixed $value): bool
    {
        if (! $value instanceof stdClass) {
            return false;
        }

        $keys = array_map(strval(...), array_keys(get_object_vars($value)));
        $all = [];
        $required = [];

        foreach ($this->config->getDataClass($variant)->properties as $property) {
            if ($property->hidden) {
                continue;
            }

            $name = $property->outputMappedName ?? $property->name;
            $all[] = $name;

            if (! $property->type->isOptional && $property->type->lazyType === null) {
                $required[] = $name;
            }
        }

        return array_diff($required, $keys) === [] && array_diff($keys, $all) === [];
    }

    /**
     * Every item of the given lists and maps (a map arrives as an object, or as `[]` when empty).
     *
     * @param  list<mixed>  $values
     * @return list<mixed>
     */
    private function items(array $values): array
    {
        $items = [];

        foreach ($values as $value) {
            if (is_array($value)) {
                array_push($items, ...array_values($value));
            } elseif ($value instanceof stdClass) {
                array_push($items, ...array_values(get_object_vars($value)));
            }
        }

        return $items;
    }

    /**
     * Record whether one state of one property was seen at one place.
     *
     * @param  class-string  $class
     * @param  string  $state  such as "is only ever null"
     * @param  string  $wanted  the value a test should send, completing "with <path> …" (or the whole phrase when
     *                          $fixIsComplete), such as "not null"
     */
    private function shape(string $class, DataProperty $property, string $state, Origin $origin, string $path, string $wanted, bool $seen, bool $fixIsComplete = false): void
    {
        $what = class_basename($class).'.'.($property->outputMappedName ?? $property->name)." {$state}";
        $key = "{$class}::{$property->name}::{$state}";
        $at = "{$origin->label} › {$path}";
        $fix = sprintf('add a feature test that %s with %s', $origin->action, $fixIsComplete ? $wanted : "{$path} {$wanted}");

        $this->shapes[$key] ??= ['what' => $what, 'at' => [], 'fix' => $fix, 'seen' => false];
        $this->shapes[$key]['seen'] = $this->shapes[$key]['seen'] || $seen;

        if (! in_array($at, $this->shapes[$key]['at'], true)) {
            $this->shapes[$key]['at'][] = $at;
        }
    }
}
