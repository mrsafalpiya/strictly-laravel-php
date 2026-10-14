<?php

namespace StrictlyLaravel\Data;

use Carbon\CarbonImmutable;
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
    public function cast(DataProperty $property, mixed $value, array $properties, CreationContext $context): CarbonImmutable
    {
        if ($value instanceof DateTimeInterface) {
            return CarbonImmutable::instance($value)->startOfDay();
        }

        // Carbon throws on text that doesn't fit the format, and rolls an impossible day over (2026-02-30 is 2026-03-02).
        $date = is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1
            ? CarbonImmutable::createFromFormat('!'.self::FORMAT, $value)
            : null;

        if (! $date instanceof CarbonImmutable || $date->format(self::FORMAT) !== $value) {
            $field = implode('.', [...$context->currentPath, $property->inputMappedName ?? $property->name]);

            throw ValidationException::withMessages([
                $field => __('validation.date_format', ['attribute' => $field, 'format' => self::FORMAT]),
            ]);
        }

        return $date;
    }

    public function transform(DataProperty $property, mixed $value, TransformationContext $context): mixed
    {
        return $value instanceof DateTimeInterface ? $value->format(self::FORMAT) : $value;
    }
}
