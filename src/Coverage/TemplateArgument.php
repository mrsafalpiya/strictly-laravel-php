<?php

namespace StrictlyLaravel\Coverage;

use Illuminate\Support\Enumerable;
use PHPStan\PhpDocParser\Ast\PhpDoc\TemplateTagValueNode;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use ReflectionClass;
use Spatie\LaravelData\Contracts\BaseData;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Lazy;
use Spatie\LaravelData\Optional;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\TypeScriptTransformer\Actions\FindClassNameFqcnAction;
use Spatie\TypeScriptTransformer\PhpNodes\PhpClassNode;
use StrictlyLaravel\TypeScript\ClassTemplates;
use StrictlyLaravel\TypeScript\PropertyAnnotation;

/**
 * What a generic Data class's template stands for where the class is used (Problem P-185). laravel-data describes a
 * template-typed property (`@param list<TItem> $items`) as an array with no Data class, since a template is no class,
 * so Contract Coverage would only check that it isn't always empty. The use's PHPDoc type (`ListingData<IssueData>`, on
 * a page prop, a Data property or a list's items) is what TypeScript instantiates the generated `ListingData<TItem>`
 * with, so this binds each template to its type argument there, and Contract Coverage walks a template-typed property
 * (`TItem`, `list<TItem>`, `array<string, TItem>`) as that Data class, at any depth.
 *
 * A use with no type argument falls back to the template's bound. When that is no one Data class (`of Data`, an
 * abstract Data class, no bound at all), the template is unbound, and Contract Coverage reports the property instead
 * of passing it silently. A template bound to a type that holds no Data object (`int`, an enum) has nothing to walk.
 */
final readonly class TemplateArgument
{
    /** A generic Data class whose bounds or type arguments name generic Data classes in a cycle stops here. */
    private const int MAX_DEPTH = 8;

    /** The PHPDoc types of a list or map whose last type argument is its item. */
    private const array LISTS = ['list', 'array', 'non-empty-list', 'non-empty-array', 'iterable'];

    /**
     * @param  string  $label  how the type is written, with its type arguments: `ListingData<IssueData>`
     * @param  list<class-string<Data>>  $classes  the Data classes a value is one of (more than one for a union); none
     *                                             when it holds no Data object, or is unbound
     * @param  array<string, self>  $arguments  the templates of the one class, bound
     * @param  array{class: class-string, template: string}|null  $unbound  the template no type argument or bound gives
     *                                                                      a Data class
     * @param  bool  $generic  whether a template or a generic Data class was met, which laravel-data can't describe
     */
    private function __construct(
        public string $label,
        public array $classes = [],
        public array $arguments = [],
        public ?array $unbound = null,
        public bool $generic = false,
    ) {}

    /**
     * What a property holds where laravel-data can't tell: a template type, or a generic Data class with its type
     * arguments. Null when laravel-data's description of the property is the whole of it.
     *
     * @param  class-string  $owner  the class the property is walked as
     * @param  array<string, self>  $arguments  $owner's templates, bound by the use being walked
     * @return array{type: self, items: bool}|null what the value is, or what each item of the list or map is
     */
    public static function ofProperty(DataProperty $property, string $owner, array $arguments): ?array
    {
        if (! class_exists($property->className)) {
            return null;
        }

        $annotation = PropertyAnnotation::of($property->className, $property->name);

        if ($annotation === null) {
            // No PHPDoc type: a generic Data class written with no type arguments.
            $class = $property->type->dataClass;

            if ($class === null || ! is_subclass_of($class, Data::class) || self::templates($class) === []) {
                return null;
            }

            return ['type' => self::data($class, [], null, null, [], 0), 'items' => $property->type->kind->isDataCollectable()];
        }

        $node = self::present($annotation->type);
        $item = self::item($node, $annotation->scope);
        $type = self::resolve($item ?? $node, $annotation->scope, $owner, $arguments, 0);

        return $type->generic ? ['type' => $type, 'items' => $item !== null] : null;
    }

    /**
     * How a Data class is written with the type arguments it is walked with: `ListingData<IssueData>`.
     *
     * @param  class-string  $class
     * @param  array<string, self>  $arguments
     */
    public static function labelOf(string $class, array $arguments): string
    {
        $labels = array_map(fn (self $argument): string => $argument->label, array_values($arguments));

        return class_basename($class).($labels === [] ? '' : '<'.implode(', ', $labels).'>');
    }

    /**
     * The type a value is when present: without `null`, Optional or a laravel-data Lazy.
     */
    private static function present(TypeNode $type): TypeNode
    {
        if ($type instanceof NullableTypeNode) {
            return self::present($type->type);
        }

        if (! $type instanceof UnionTypeNode) {
            return $type;
        }

        $members = array_values(array_filter($type->types, fn (TypeNode $member): bool => ! $member instanceof IdentifierTypeNode
            || ! in_array(ltrim($member->name, '\\'), ['null', 'Optional', 'Lazy', Optional::class, Lazy::class], true)));

        return count($members) === 1 ? self::present($members[0]) : new UnionTypeNode($members);
    }

    /**
     * The item type of a list or map (`list<TItem>`, `array<string, TItem>`, `TItem[]`, a DataCollection or a
     * Collection of them), or null when the type is no list or map.
     */
    private static function item(TypeNode $type, PhpClassNode $scope): ?TypeNode
    {
        if ($type instanceof ArrayTypeNode) {
            return $type->type;
        }

        if (! $type instanceof GenericTypeNode || $type->genericTypes === []) {
            return null;
        }

        $item = $type->genericTypes[count($type->genericTypes) - 1];

        if (in_array(strtolower($type->type->name), self::LISTS, true)) {
            return $item;
        }

        $class = self::className($type->type->name, $scope);

        return $class !== null && (is_a($class, DataCollection::class, true) || is_a($class, Enumerable::class, true)) ? $item : null;
    }

    /**
     * @param  class-string  $owner  whose templates the type may name
     * @param  array<string, self>  $arguments  $owner's templates, bound
     */
    private static function resolve(TypeNode $type, PhpClassNode $scope, string $owner, array $arguments, int $depth): self
    {
        $type = self::present($type);

        if ($depth > self::MAX_DEPTH) {
            return new self((string) $type);
        }

        if ($type instanceof UnionTypeNode) {
            $members = array_map(fn (TypeNode $member): self => self::resolve($member, $scope, $owner, $arguments, $depth + 1), $type->types);
            $generic = array_any($members, fn (self $member): bool => $member->generic);

            foreach ($members as $member) {
                if ($member->unbound !== null) {
                    return $member;
                }
            }

            $classes = array_values(array_unique(array_merge(...array_map(fn (self $member): array => $member->classes, $members))));

            return new self(implode('|', array_map(fn (self $member): string => $member->label, $members)), $classes, generic: $generic);
        }

        if ($type instanceof IdentifierTypeNode) {
            $templates = self::templates($owner);

            if (isset($templates[$type->name])) {
                $argument = $arguments[$type->name] ?? self::bound($owner, $type->name, $templates[$type->name], $depth + 1);

                return new self($argument->label, $argument->classes, $argument->arguments, $argument->unbound, generic: true);
            }

            $class = self::className($type->name, $scope);

            return $class !== null && self::isConcreteData($class)
                ? self::data($class, [], $scope, $owner, $arguments, $depth + 1)
                : new self(class_basename($class ?? $type->name));
        }

        if ($type instanceof GenericTypeNode) {
            $class = self::className($type->type->name, $scope);

            return $class !== null && self::isConcreteData($class)
                ? self::data($class, $type->genericTypes, $scope, $owner, $arguments, $depth + 1)
                : new self((string) $type);
        }

        return new self((string) $type);
    }

    /**
     * A Data class with its templates bound to the given type arguments, or to their bounds where there are none.
     *
     * @param  class-string<Data>  $class
     * @param  list<TypeNode>  $typeArguments
     * @param  class-string|null  $owner  whose templates the type arguments may name
     * @param  array<string, self>  $arguments  $owner's templates, bound
     */
    private static function data(string $class, array $typeArguments, ?PhpClassNode $scope, ?string $owner, array $arguments, int $depth): self
    {
        $bound = [];
        $i = 0;

        foreach (self::templates($class) as $name => $template) {
            $bound[$name] = isset($typeArguments[$i]) && $scope !== null && $owner !== null
                ? self::resolve($typeArguments[$i], $scope, $owner, $arguments, $depth + 1)
                : self::bound($class, $name, $template, $depth + 1);
            $i++;
        }

        return new self(self::labelOf($class, $bound), [$class], $bound, generic: $bound !== []);
    }

    /**
     * What a template is when a use gives it no type argument: its bound, when that holds no Data object or is one
     * Data class, and unbound otherwise.
     *
     * @param  class-string  $class
     */
    private static function bound(string $class, string $name, TemplateTagValueNode $template, int $depth): self
    {
        $unbound = new self($name, unbound: ['class' => $class, 'template' => $name], generic: true);

        if ($template->bound === null) {
            return $unbound;
        }

        $scope = new PhpClassNode(new ReflectionClass($class));
        $type = self::resolve($template->bound, $scope, $class, [], $depth + 1);

        if ($type->classes === [] && self::mayHoldData($template->bound, $scope)) {
            return $unbound;
        }

        return new self($type->label, $type->classes, $type->arguments, $type->unbound, generic: true);
    }

    /**
     * Whether a type that names no concrete Data class may still hold a Data object: `mixed`, `object`, laravel-data's
     * own Data classes and an abstract one.
     */
    private static function mayHoldData(TypeNode $type, PhpClassNode $scope): bool
    {
        $type = self::present($type);

        if ($type instanceof UnionTypeNode) {
            return array_any($type->types, fn (TypeNode $member): bool => self::mayHoldData($member, $scope));
        }

        if (! $type instanceof IdentifierTypeNode) {
            return false;
        }

        if (in_array(strtolower($type->name), ['mixed', 'object'], true)) {
            return true;
        }

        $class = self::className($type->name, $scope);

        return $class !== null && is_a($class, BaseData::class, true);
    }

    /**
     * @param  class-string  $class
     */
    private static function isConcreteData(string $class): bool
    {
        return is_subclass_of($class, Data::class) && ! (new ReflectionClass($class))->isAbstract();
    }

    /**
     * @param  class-string  $class
     * @return array<string, TemplateTagValueNode>
     */
    private static function templates(string $class): array
    {
        return ClassTemplates::of(new PhpClassNode(new ReflectionClass($class)));
    }

    /**
     * @return class-string|null
     */
    private static function className(string $name, PhpClassNode $scope): ?string
    {
        $class = (new FindClassNameFqcnAction)->execute($scope, $name);

        return class_exists($class) || interface_exists($class) ? $class : null;
    }
}
