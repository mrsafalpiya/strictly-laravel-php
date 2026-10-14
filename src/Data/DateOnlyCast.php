<?php

namespace StrictlyLaravel\Data;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeInterface;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Casts\Cast;
use Spatie\LaravelData\Support\Creation\CreationContext;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Transformation\TransformationContext;
use Spatie\LaravelData\Transformers\Transformer;

/**
 * A calendar date without time or timezone: `"2026-10-14"` in, `"2026-10-14"` out. Used through `#[DateOnly]`.
 *
 * laravel-data infers no rule for a date property, so this cast is what rejects a value that isn't a real
 * `YYYY-MM-DD` date: a validation error on the field rather than laravel-data's `CannotCastDate`, a 500.
 */
final class DateOnlyCast implements Cast, Transformer
{
    public const string FORMAT = 'Y-m-d';

    /**
     * @param  array<array-key, mixed>  $properties
     */
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): CarbonInterface
    {
        $class = self::carbonClassFor($property);

        if ($value instanceof DateTimeInterface) {
            return $class::instance($value)->startOfDay();
        }

        // Carbon throws on text that doesn't fit the format, and rolls an impossible day over (2026-02-30 is 2026-03-02).
        $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? $class::createFromFormat('!'.self::FORMAT, $value)
            : null;

        if (! $date instanceof CarbonInterface || $date->format(self::FORMAT) !== $value) {
            $field = implode('.', [...$context->currentPath, $property->inputMappedName ?? $property->name]);

            throw ValidationException::withMessages([
                $field => __('validation.date_format', ['attribute' => $field, 'format' => self::FORMAT]),
            ]);
        }

        return $date;
    }

    /**
     * The Carbon class the property is declared with, so a mutable `Carbon` property gets a `Carbon`: the declared
     * class when it is `Carbon`, `CarbonImmutable` or a subclass, `Carbon` for a `DateTime`, and `CarbonImmutable` for
     * an interface (`CarbonInterface`, `DateTimeInterface`) or a `DateTimeImmutable`.
     *
     * @return class-string<Carbon|CarbonImmutable>
     */
    private static function carbonClassFor(DataProperty $property): string
    {
        $declared = $property->type->type->findAcceptedTypeForBaseType(DateTimeInterface::class) ?? CarbonImmutable::class;

        return match (true) {
            is_a($declared, Carbon::class, true), is_a($declared, CarbonImmutable::class, true) => $declared,
            is_a(Carbon::class, $declared, true) && ! is_a(CarbonImmutable::class, $declared, true) => Carbon::class,
            default => CarbonImmutable::class,
        };
    }

    public function transform(DataProperty $property, mixed $value, TransformationContext $context): mixed
    {
        return $value instanceof DateTimeInterface ? $value->format(self::FORMAT) : $value;
    }
}
