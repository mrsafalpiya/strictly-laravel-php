<?php

namespace StrictlyLaravel\Coverage;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Enums\DataTypeKind;
use Spatie\LaravelData\Support\DataConfig;
use Spatie\LaravelData\Support\DataProperty;
use stdClass;
use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\TypeScript\LiteralValues;

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
     * A shape is reported only when some observe() required it; observeAccepted() can only mark one seen.
     *
     * @var array<string, array{what: string, at: list<string>, fix: string, seen: bool, required: bool}>
     */
    private array $shapes = [];

    /** Whether the values being walked are accepted submissions (observeAccepted()). */
    private bool $accepted = false;

    public function __construct(
        private readonly DataConfig $config,
    ) {}

    /**
     * Every value recorded for one Data class (decoded with objects as stdClass), from one origin.
     *
     * @param  class-string<Data>  $class
     * @param  list<mixed>  $values
     * @param  list<string>  $absenceReported  the class's own properties (by output name) whose absence another
     *                                         dimension reports, such as a page's deferred props
     * @param  array<string, TemplateArgument>  $arguments  a generic class's templates, bound by the type it is used as,
     *                                                      such as a JSON endpoint's `@return` (Problem P-197)
     */
    public function observe(string $class, array $values, Origin $origin, array $absenceReported = [], array $arguments = []): void
    {
        $this->walk($class, $values, $origin, '', $absenceReported, $arguments);
    }

    /**
     * Requests a form endpoint accepted (validated without errors), keyed by input name, for its input class. Problem
     * P-125: an input class is also a page's form defaults, which a create page sends empty by design, so no page can
     * show its nullable fields filled in. The same type is what the form sends back, so a value the server accepted
     * shows that state too. These values only mark states as seen: a field the tests never submit is no gap of its
     * own, since a form endpoint's whole input is checked by its `form` Contracts.
     *
     * @param  class-string<Data>  $class
     * @param  list<mixed>  $values
     */
    public function observeAccepted(string $class, array $values, Origin $origin): void
    {
        $this->accepted = true;

        try {
            $this->walk($class, $values, $origin, '');
        } finally {
            $this->accepted = false;
        }
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
            if ($shape['required'] && ! $shape['seen']) {
                $gaps["{$shape['what']}\0{$key}"] = new Gap($shape['what'], $shape['at'], $shape['fix'], unexercised: false);
            }
        }

        ksort($gaps);

        return array_values($gaps);
    }

    /**
     * @param  class-string  $class
     * @param  list<mixed>  $values
     * @param  list<string>  $absenceReported
     * @param  array<string, TemplateArgument>  $arguments  $class's templates, bound by the use being walked (P-185)
     */
    private function walk(string $class, array $values, Origin $origin, string $prefix, array $absenceReported = [], array $arguments = []): void
    {
        $objects = array_values(array_filter($values, fn (mixed $value): bool => $value instanceof stdClass));

        if ($objects === []) {
            return;
        }

        foreach ($this->config->getDataClass($class)->properties as $property) {
            if ($property->hidden) {
                continue;
            }

            // A submission names its fields as the input class reads them, a page prop as the class writes them.
            $name = $this->accepted ? ($property->inputMappedName ?? $property->name) : ($property->outputMappedName ?? $property->name);
            $path = $prefix === '' ? $name : "{$prefix}.{$name}";
            $present = [];

            foreach ($objects as $object) {
                if (property_exists($object, $name)) {
                    $present[] = $object->{$name};
                }
            }

            if (($property->type->isOptional || $property->type->lazyType !== null) && ! in_array($name, $absenceReported, true)) {
                $this->shape($class, $property, 'is only ever absent', $origin, $path, 'sent', seen: $present !== []);
            }

            $nonNull = array_values(array_filter($present, fn (mixed $value): bool => $value !== null));

            if ($property->type->isNullable && $present !== []) {
                $this->shape($class, $property, 'is only ever null', $origin, $path, 'not null', seen: $nonNull !== []);
            }

            if ($nonNull !== []) {
                $this->scalarMembers($class, $property, $nonNull, $origin, $path);
                $this->walkValue($class, $property, $nonNull, $origin, $path, $arguments);
            }
        }
    }

    /**
     * @param  class-string  $owner
     * @param  list<mixed>  $values  the property's non-null values
     * @param  array<string, TemplateArgument>  $arguments  $owner's templates, bound
     */
    private function walkValue(string $owner, DataProperty $property, array $values, Origin $origin, string $path, array $arguments): void
    {
        $type = $property->type;
        $wrapped = WrappedProp::of($property);

        if ($wrapped !== null) {
            $this->walkWrapped($owner, $property, $wrapped, $values, $origin, $path);

            return;
        }

        $template = TemplateArgument::ofProperty($property, $owner, $arguments);

        if ($template !== null) {
            $this->walkTemplate($owner, $property, $template['type'], $template['items'], $arguments, $values, $origin, $path);

            return;
        }

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

            $this->walkVariants($owner, $property, $variants, $values, $origin, $path);

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
     * A value that is one of a union's Data classes, each of which must be seen.
     *
     * @param  class-string  $owner
     * @param  list<class-string<Data>>  $variants
     * @param  list<mixed>  $values
     * @param  string|null  $label  how $owner is written with its type arguments, for a template-typed property
     */
    private function walkVariants(string $owner, DataProperty $property, array $variants, array $values, Origin $origin, string $path, ?string $label = null): void
    {
        foreach ($variants as $variant) {
            $matching = array_values(array_filter($values, fn (mixed $value): bool => $this->isVariant($variant, $value)));
            $variantName = class_basename($variant);

            $this->shape($owner, $property, "is never seen as {$variantName}", $origin, $path, "as {$variantName}", seen: $matching !== [], label: $label);
            $this->walk($variant, $matching, $origin, $path);
        }
    }

    /**
     * A property whose type is a template of a generic Data class, or a list or map of it, or a generic Data class
     * itself, walked as the Data class the use being walked binds it to (Problem P-185). Its shapes are kept per use
     * (`ListingData<IssueData>.items`), since each use is a different TypeScript type.
     *
     * @param  class-string  $owner
     * @param  array<string, TemplateArgument>  $arguments  $owner's templates, bound
     * @param  list<mixed>  $values  the property's non-null values
     */
    private function walkTemplate(string $owner, DataProperty $property, TemplateArgument $type, bool $items, array $arguments, array $values, Origin $origin, string $path): void
    {
        $label = $arguments === [] ? null : TemplateArgument::labelOf($owner, $arguments);

        if ($type->unbound !== null) {
            $declaring = class_basename($type->unbound['class']);
            $template = $type->unbound['template'];

            $this->shape($owner, $property, "holds {$template} with no Data class", $origin, $path, '', seen: false, fix: sprintf(
                'give %1$s a type argument where it is used (%1$s<SomeData>), or bound %2$s by one Data class, so Contract Coverage can check what it holds',
                $declaring,
                $template,
            ));
        }

        if ($items) {
            $values = $this->items($values);
            $this->shape($owner, $property, 'is only ever empty', $origin, $path, "at least one item in {$path}", seen: $values !== [], fixIsComplete: true, label: $label);
            $path = "{$path}[]";
        }

        if (count($type->classes) === 1) {
            $this->walk($type->classes[0], $values, $origin, $path, arguments: $type->arguments);
        } elseif ($type->classes !== []) {
            $this->walkVariants($owner, $property, $type->classes, $values, $origin, $path, $label);
        }
    }

    /**
     * A prop wrapped in Inertia's ScrollProp, MergeProp or OnceProp, walked as the collection or Data object it sends
     * (Problem P-133).
     *
     * @param  class-string  $owner
     * @param  list<mixed>  $values  the property's non-null values
     */
    private function walkWrapped(string $owner, DataProperty $property, WrappedProp $wrapped, array $values, Origin $origin, string $path): void
    {
        if ($wrapped->collection === null) {
            $this->walk($wrapped->dataClass, $values, $origin, $path);

            return;
        }

        $items = $this->items($wrapped->collection === 'paginated'
            ? array_map(fn (mixed $value): mixed => $value instanceof stdClass ? ($value->data ?? []) : [], $values)
            : $values);

        $this->shape($owner, $property, 'is only ever empty', $origin, $path, "at least one item in {$path}", seen: $items !== [], fixIsComplete: true);
        $this->walk($wrapped->dataClass, $items, $origin, "{$path}[]");
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
     * nothing the class doesn't have, and for each property typed as a literal (the discriminant of a discriminated
     * union, such as a `type` documented `@var ActivityType::Commented`) one of its values. Variants with the same keys are told apart by
     * the literal, as `tsc` does (Problem P-132).
     *
     * @param  class-string  $variant
     */
    private function isVariant(string $variant, mixed $value): bool
    {
        if (! $value instanceof stdClass) {
            return false;
        }

        $sent = get_object_vars($value);
        $keys = array_map(strval(...), array_keys($sent));
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

            $literals = array_key_exists($name, $sent) && $sent[$name] !== null
                ? LiteralValues::ofProperty($variant, $property->name)
                : null;

            if ($literals !== null && ! in_array($sent[$name], $literals, true)) {
                return false;
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
     * @param  string|null  $label  how $class is written with the type arguments of the use, such as
     *                              `ListingData<IssueData>`, when the shape is kept per use (P-185)
     * @param  string|null  $fix  what to do, when it is no feature test to add
     */
    private function shape(string $class, DataProperty $property, string $state, Origin $origin, string $path, string $wanted, bool $seen, bool $fixIsComplete = false, ?string $label = null, ?string $fix = null): void
    {
        $what = ($label ?? class_basename($class)).'.'.($property->outputMappedName ?? $property->name)." {$state}";
        $key = $label === null ? "{$class}::{$property->name}::{$state}" : "{$class}::{$label}::{$property->name}::{$state}";
        $at = "{$origin->label} › {$path}";

        if ($fix === null) {
            $fix = sprintf('add a feature test that %s with %s', $origin->action, $fixIsComplete ? $wanted : "{$path} {$wanted}");

            if (is_subclass_of($class, InputData::class)) {
                // observeAccepted(): a submission the server accepted shows the state as well.
                $field = $property->inputMappedName ?? $property->name;
                $fix .= sprintf(', or one whose request a form endpoint accepts with %s\'s %s %s', class_basename($class), $field, $fixIsComplete ? 'not empty' : $wanted);
            }
        }

        $this->shapes[$key] ??= ['what' => $what, 'at' => [], 'fix' => $fix, 'seen' => false, 'required' => false];
        $this->shapes[$key]['seen'] = $this->shapes[$key]['seen'] || $seen;

        if ($this->accepted) {
            return;
        }

        if (! $this->shapes[$key]['required']) {
            $this->shapes[$key]['required'] = true;
            $this->shapes[$key]['fix'] = $fix;
        }

        if (! in_array($at, $this->shapes[$key]['at'], true)) {
            $this->shapes[$key]['at'][] = $at;
        }
    }
}
