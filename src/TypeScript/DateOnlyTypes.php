<?php

namespace StrictlyLaravel\TypeScript;

use DateTimeInterface;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use Spatie\TypeScriptTransformer\PhpNodes\PhpPropertyNode;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\References\CustomReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;
use Spatie\TypeScriptTransformer\Transformers\ClassPropertyProcessors\ClassPropertyProcessor;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIdentifier;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptNode;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptRaw;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptUnion;
use StrictlyLaravel\Data\DateOnly;

/**
 * Problem P-023: the TypeScript side of `#[DateOnly]`. Every date is otherwise generated as an ISO-8601 `string`, so
 * a due date and a timestamp would look the same to React. As a provider this generates the `DateOnly` type
 * (`YYYY-MM-DD`) and the `isDateOnly()` guard that turns a string such as an `<input type="date">` value into one
 * without a cast; as a property processor it types every `#[DateOnly]` property as `DateOnly`.
 */
final class DateOnlyTypes implements ClassPropertyProcessor, TransformedProvider
{
    public function provide(): array
    {
        return [
            // A calendar date, `YYYY-MM-DD`: a `#[DateOnly]` property. Other dates are ISO-8601 strings.
            new Transformed(
                new TypeScriptAlias(new TypeScriptIdentifier('DateOnly'), new TypeScriptRaw('`${number}-${number}-${number}`')),
                self::type(),
                [],
                true,
            ),
            new Transformed(
                new TypeScriptRaw(<<<'TS'
                    /** Whether a string, such as an `<input type="date">` value, is a real calendar date as `#[DateOnly]` reads it. */
                    export function isDateOnly(value: string): value is %DateOnly% {
                        const date = new Date(`${value}T00:00:00Z`);

                        return /^\d{4}-\d{2}-\d{2}$/.test(value) && !Number.isNaN(date.getTime()) && date.toISOString().startsWith(value);
                    }
                    TS, references: ['DateOnly' => self::type()]),
                new CustomReference('strictly', 'isDateOnly'),
                [],
                false,
            ),
        ];
    }

    public function execute(PhpPropertyNode $phpPropertyNode, ?TypeNode $annotation, TypeScriptProperty $property): TypeScriptProperty
    {
        if ($phpPropertyNode->getAttributes(DateOnly::class) !== []) {
            $property->type = $this->dateOnly($property->type);
        }

        return $property;
    }

    private static function type(): CustomReference
    {
        return new CustomReference('strictly', 'DateOnly');
    }

    /** Replaces the date in `CarbonImmutable`, `?CarbonImmutable` or a Lazy union, keeping the rest. */
    private function dateOnly(TypeScriptNode $type): TypeScriptNode
    {
        if ($type instanceof TypeScriptUnion) {
            $type->types = array_map($this->dateOnly(...), $type->types);

            return $type;
        }

        if (
            $type instanceof TypeScriptReference
            && $type->reference instanceof ClassStringReference
            && is_a($type->reference->classString, DateTimeInterface::class, true)
        ) {
            return new TypeScriptReference(self::type());
        }

        return $type;
    }
}
