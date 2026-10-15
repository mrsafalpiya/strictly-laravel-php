<?php

namespace StrictlyLaravel\PHPStan;

use PhpParser\Node;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\ClassLike;
use PHPStan\Analyser\Scope;
use PHPStan\Node\InClassNode;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Rules\IdentifierRuleError;
use PHPStan\Rules\Rule;
use PHPStan\Rules\RuleErrorBuilder;
use PHPStan\Type\Constant\ConstantStringType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeTraverser;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Contracts\BaseData;
use StrictlyLaravel\Data\InputData;

/**
 * Problem P-099: laravel-data merges a Data class's rules() with the rules inferred from its types only when that class
 * has #[MergeValidationRules], and it reads the attribute from each nested Data class separately. So a plain Data class
 * with rules() nested in an input (a property of its type, or an item of a list, a map or a DataCollection of it, at
 * any depth) replaces the rules inferred for it: `['title' => ['max:50']]` drops `required|string` for `task.title`.
 * Such a class must extend InputData too, like the input itself (strictly.inputData, InputDataRule).
 *
 * Every input class and query class (InputDataRule::REQUEST_DATA) is checked from its own properties. A nested class
 * that extends one of them is checked as its own input, so the walk stops there.
 *
 * @implements Rule<InClassNode>
 */
final readonly class NestedInputDataRule implements Rule
{
    public function __construct(
        private ReflectionProvider $reflection,
    ) {}

    public function getNodeType(): string
    {
        return InClassNode::class;
    }

    /**
     * @return list<IdentifierRuleError>
     */
    public function processNode(Node $node, Scope $scope): array
    {
        $input = $node->getClassReflection();

        if (! $this->isRequestData($input)) {
            return [];
        }

        $errors = [];

        foreach ($this->nestedDataClasses($input) as $property => $classes) {
            $seen = [];
            $offenders = [];

            foreach ($classes as $nested) {
                array_push($offenders, ...$this->offenders($nested, [], $seen));
            }

            foreach ($offenders as [$offender, $through]) {
                $errors[] = RuleErrorBuilder::message(sprintf(
                    "%s::\$%s nests %s%s, whose rules() replace the rules inferred from its types because it doesn't extend %s.",
                    $input->getName(), $property, $offender, $through === [] ? '' : ' (through '.implode(', ', $through).')',
                    InputData::class,
                ))
                    ->identifier('strictly.inputData')
                    ->line($this->line($node->getOriginalNode(), $property))
                    ->tip(sprintf(
                        'Make %s extend %s: its rules() then merge with the inferred ones, as the input\'s own do.',
                        $offender, InputData::class,
                    ))
                    ->build();
            }
        }

        return $errors;
    }

    private function isRequestData(ClassReflection $class): bool
    {
        foreach (InputDataRule::REQUEST_DATA as $base) {
            if ($class->isSubclassOf($base)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The Data classes nested in a plain Data class (itself included) whose rules() replace their inferred rules,
     * each with the classes it is nested through.
     *
     * @param  list<string>  $through
     * @param  array<string, true>  $seen
     * @return list<array{string, list<string>}>
     */
    private function offenders(ClassReflection $data, array $through, array &$seen): array
    {
        if ($this->isRequestData($data) || isset($seen[$data->getName()])) {
            return [];
        }

        $seen[$data->getName()] = true;
        $offenders = $data->hasNativeMethod('rules') ? [[$data->getName(), $through]] : [];

        foreach ($this->nestedDataClasses($data) as $classes) {
            foreach ($classes as $nested) {
                array_push($offenders, ...$this->offenders($nested, [...$through, $data->getName()], $seen));
            }
        }

        return $offenders;
    }

    /**
     * The Data classes each validated property of a Data class nests directly: its type, the item type of a list, a
     * map or a DataCollection, or a #[DataCollectionOf] class.
     *
     * @return array<string, list<ClassReflection>>
     */
    private function nestedDataClasses(ClassReflection $data): array
    {
        $nested = [];

        foreach ($data->getNativeReflection()->getProperties() as $native) {
            if (! $native->isPublic() || $native->isStatic()) {
                continue;
            }

            $property = $data->getNativeProperty($native->getName());
            $names = $this->classNames($property->getReadableType());

            foreach ($property->getAttributes() as $attribute) {
                if ($attribute->getName() === DataCollectionOf::class) {
                    foreach ($attribute->getArgumentTypes() as $argument) {
                        array_push($names, ...array_map(
                            fn (ConstantStringType $string): string => $string->getValue(),
                            $argument->getConstantStrings(),
                        ));
                    }
                }
            }

            $classes = [];

            foreach (array_unique($names) as $name) {
                if ($this->reflection->hasClass($name) && $this->reflection->getClass($name)->implementsInterface(BaseData::class)) {
                    $classes[] = $this->reflection->getClass($name);
                }
            }

            if ($classes !== []) {
                $nested[$native->getName()] = $classes;
            }
        }

        return $nested;
    }

    /**
     * Every class named anywhere in a type: union members, array keys and items, and generic type arguments.
     *
     * @return list<string>
     */
    private function classNames(Type $type): array
    {
        $names = [];

        TypeTraverser::map($type, function (Type $type, callable $traverse) use (&$names): Type {
            array_push($names, ...$type->getObjectClassNames());

            return $traverse($type);
        });

        return $names;
    }

    /**
     * The line declaring a property of the input, which may be promoted from its constructor.
     */
    private function line(ClassLike $class, string $property): int
    {
        foreach ($class->getProperties() as $declaration) {
            foreach ($declaration->props as $item) {
                if ($item->name->toString() === $property) {
                    return $item->getStartLine();
                }
            }
        }

        foreach ($class->getMethod('__construct')->params ?? [] as $param) {
            if ($param->isPromoted() && $param->var instanceof Variable && $param->var->name === $property) {
                return $param->var->getStartLine();
            }
        }

        return $class->getStartLine();
    }
}
