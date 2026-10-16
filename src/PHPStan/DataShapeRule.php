<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node;
use PHPStan\Analyser\Scope;
use PHPStan\Node\ClassPropertyNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Generic\TemplateType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeTraverser;
use PHPStan\Type\UnionType;
use PHPStan\Type\VerbosityLevel;
use ReflectionProperty;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Contracts\BaseData;

/**
 * Problem P-084: Contract Coverage reads a property's shapes from laravel-data's description of its Data class, which
 * knows one item type per list or map and nothing inside a PHPDoc array shape. So a public property of a Data class
 * (Page, input and query classes included) may not hold, at any depth:
 *
 * - an array shape (`array{lead: ?string}`), whose fields' shapes no test has to send;
 * - a list or map whose item type is a union (`list<IssueData|MilestoneData>`, `list<int|string>`, `list<?string>`),
 *   whose members no test has to send (and laravel-data reads every item of a union of Data classes as the first);
 * - a list or map of lists or maps (`list<list<MemberData>>`), whose inner items Contract Coverage never walks.
 *
 * Each is modelled as a Data class instead, whose properties Contract Coverage checks. For input and query classes the
 * same shapes are also unvalidated: laravel-data infers only `array` for them. Items of literals of one scalar type
 * (`list<'low'|'high'>`) or of `int|float` stay allowed, since Contract Coverage counts a scalar union's members by
 * their TypeScript type (`string`, `number`) at the top level too, and so do items of a template type with a union
 * bound, which each use of the class decides: a use's type arguments are checked in the class's template-typed
 * properties, so `ListingData<IssueData|MilestoneData>` is rejected where `ListingData` has `list<TItem>` (P-185).
 *
 * @implements Rule<ClassPropertyNode>
 */
final class DataShapeRule implements Rule
{
    public function getNodeType(): string
    {
        return ClassPropertyNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $class = $node->getClassReflection();
        $name = $node->getName();

        if (! $node->isPublic() || $node->isStatic() || ! $class->implementsInterface(BaseData::class)
            || ! $class->hasNativeProperty($name)) {
            return [];
        }

        $native = $class->getNativeReflection();

        if ($native->hasProperty($name) && $native->getProperty($name)->getAttributes(Hidden::class) !== []) {
            return []; // never sent, so never generated nor covered
        }

        $problem = $this->problem($class->getNativeProperty($name)->getReadableType(), item: false, scope: $scope);

        if ($problem === null) {
            return [];
        }

        [$kind, $type] = $problem;
        $property = "{$class->getName()}::\${$name}";
        $described = $type->describe(VerbosityLevel::value());

        [$message, $tip] = match ($kind) {
            'shape' => [
                "{$property} has an array shape ({$described}) in its type, inside which Contract Coverage can't see.",
                'Model the shape as a Data class, whose every property Contract Coverage checks: list<ProjectSummaryData> with public ?string $lead on ProjectSummaryData, instead of list<array{lead: ?string}>.',
            ],
            'data union' => [
                "{$property} holds items of a union type ({$described}), whose members Contract Coverage can't tell apart.",
                "Model the item as a Data class whose one property is the union, its variants told apart by a literal discriminant (/** @var 'issue' */ public string \$type): list<FocusItemData> with public IssueData|MilestoneData \$subject on FocusItemData. Contract Coverage checks each variant of such a property (P-132).",
            ],
            'union' => [
                "{$property} holds items of a union type ({$described}), whose members Contract Coverage can't tell apart.",
                'Model the item as a Data class whose property has the union type: list<EstimateData> with public int|string $value on EstimateData, whose every member Contract Coverage checks.',
            ],
            default => [
                "{$property} holds items that are lists or maps themselves ({$described}), inside which Contract Coverage can't see.",
                'Model the item as a Data class that holds the inner list or map: list<ColumnData> with /** @var list<IssueData> */ public array $issues on ColumnData, instead of list<list<IssueData>>.',
            ],
        };

        return [RuleErrorBuilder::message($message)->identifier('strictly.dataShape')->tip($tip)->build()];
    }

    /**
     * The first shape Contract Coverage can't see in a type, and the part of the type that is it: an array shape
     * anywhere comes first, then an item that is a union, then an item that is a list or map.
     *
     * @param  array<string, true>  $resolving  the generic Data classes whose uses are being resolved, by their type
     * @return array{'shape'|'data union'|'union'|'nested', Type}|null
     */
    private function problem(Type $type, bool $item, Scope $scope, array $resolving = []): ?array
    {
        $shape = $this->shape($type);

        if ($shape !== null) {
            return ['shape', $shape];
        }

        if ($item && ! $type instanceof TemplateType && $type instanceof UnionType && count($this->kinds($type)) > 1) {
            return [$this->allData($type) ? 'data union' : 'union', $type];
        }

        foreach ($this->members($type) as $member) {
            if ($item && $this->isCollection($member)) {
                return ['nested', $member];
            }

            $problem = $this->isCollection($member)
                ? $this->problem($member->getIterableValueType(), item: true, scope: $scope, resolving: $resolving)
                : $this->inGenerics($member, $scope, $resolving);

            if ($problem !== null) {
                return $problem;
            }
        }

        return null;
    }

    /**
     * A problem in a wrapper's type arguments (`ScrollProp<…>`, a generic Data class), which are values, not items,
     * or in what a generic Data class's properties become with them (Problem P-185): `ListingData<IssueData|MilestoneData>`
     * makes its `list<TItem>` a list of a union, which the class's own declaration leaves to each use.
     *
     * @param  array<string, true>  $resolving
     * @return array{'shape'|'data union'|'union'|'nested', Type}|null
     */
    private function inGenerics(Type $type, Scope $scope, array $resolving): ?array
    {
        foreach ($this->typeArguments($type) as $argument) {
            $problem = $this->problem($argument, item: false, scope: $scope, resolving: $resolving);

            if ($problem !== null) {
                return $problem;
            }
        }

        foreach ($type->getObjectClassReflections() as $class) {
            $key = $class->getDisplayName();

            if (! $class->implementsInterface(BaseData::class) || $class->getActiveTemplateTypeMap()->isEmpty() || isset($resolving[$key])) {
                continue;
            }

            foreach ($this->templateProperties($class) as $name) {
                $problem = $this->problem($type->getInstanceProperty($name, $scope)->getReadableType(), item: false, scope: $scope, resolving: [...$resolving, $key => true]);

                if ($problem !== null) {
                    return $problem;
                }
            }
        }

        return null;
    }

    /**
     * The public properties of a generic class whose declared type names one of its templates, which each use of the
     * class decides.
     *
     * @return list<string>
     */
    private function templateProperties(ClassReflection $class): array
    {
        $properties = [];

        foreach ($class->getNativeReflection()->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic() || $property->getAttributes(Hidden::class) !== []) {
                continue;
            }

            $declared = $class->getNativeProperty($property->getName())->getReadableType();
            $templated = false;

            TypeTraverser::map($declared, function (Type $type, callable $traverse) use (&$templated): Type {
                $templated = $templated || $type instanceof TemplateType;

                return $traverse($type);
            });

            if ($templated) {
                $properties[] = $property->getName();
            }
        }

        return $properties;
    }

    /** The first array shape at any depth of a type. */
    private function shape(Type $type): ?Type
    {
        foreach ($this->members($type) as $member) {
            if ($member->isConstantArray()->yes()) {
                return $member;
            }

            $inner = match (true) {
                $this->isCollection($member) => [$member->getIterableValueType()],
                default => $this->typeArguments($member),
            };

            foreach ($inner as $type) {
                $shape = $this->shape($type);

                if ($shape !== null) {
                    return $shape;
                }
            }
        }

        return null;
    }

    /**
     * A generic object's type arguments (`ScrollProp<…>`, `ListingData<IssueData>`).
     *
     * @return list<Type>
     */
    private function typeArguments(Type $type): array
    {
        $arguments = [];

        foreach ($type->getObjectClassReflections() as $class) {
            foreach ($class->getActiveTemplateTypeMap()->getTypes() as $argument) {
                $arguments[] = $argument;
            }
        }

        return $arguments;
    }

    /**
     * An array, or an iterable object such as a DataCollection or a Collection; a Data object isn't one.
     */
    private function isCollection(Type $type): bool
    {
        return $type->isArray()->yes() || ($type->isObject()->yes() && $type->isIterable()->yes()
            && ! (new ObjectType(BaseData::class))->isSuperTypeOf($type)->yes());
    }

    /**
     * @return list<Type>
     */
    private function members(Type $type): array
    {
        return $type instanceof UnionType && ! $type instanceof TemplateType ? $type->getTypes() : [$type];
    }

    /**
     * The TypeScript types a union's members are: literals of one scalar type are one, as are `int` and `float`.
     *
     * @return array<string, true>
     */
    private function kinds(UnionType $union): array
    {
        $kinds = [];

        foreach ($union->getTypes() as $member) {
            $kind = match (true) {
                $member->isNull()->yes() => ['null'],
                $member->isString()->yes() => ['string'],
                $member->isInteger()->yes(), $member->isFloat()->yes() => ['number'],
                $member->isBoolean()->yes() => ['boolean'],
                $member->isArray()->yes() => ['array'],
                $member->getObjectClassNames() !== [] => $member->getObjectClassNames(),
                default => [$member->describe(VerbosityLevel::typeOnly())],
            };

            foreach ($kind as $name) {
                $kinds[$name] = true;
            }
        }

        return $kinds;
    }

    private function allData(UnionType $union): bool
    {
        foreach ($union->getTypes() as $member) {
            if (! (new ObjectType(BaseData::class))->isSuperTypeOf($member)->yes()) {
                return false;
            }
        }

        return true;
    }
}
