<?php

namespace StrictlyLaravel\Workarounds\LaravelData;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use Spatie\LaravelData\Casts\DateTimeInterfaceCast;
use Spatie\LaravelData\Casts\Uncastable;

/**
 * Problem P-189 (docs/problems.md). Upstream: no upstream issue filed (laravel-data 4's DateTimeInterfaceCast calls
 * `createFromFormat()` on the property's type, which an interface can't answer).
 *
 * A property typed by a date interface (`public CarbonInterface $startsAt`, `?DateTimeInterface`, `list<CarbonInterface>`)
 * can't be read from a string: laravel-data throws CannotCastDate for every value, valid ones included, so the request
 * fails with a 500. This reads it as a CarbonImmutable, which implements both, and is otherwise laravel-data's cast:
 * the same formats, time zones and DateFormatRule (P-075). It is the parent of DateTimeCast, the global cast for
 * `DateTimeInterface` that StrictInputConfig registers. Delete this class once laravel-data reads an interface-typed
 * date, and make DateTimeCast extend DateTimeInterfaceCast.
 */
class InterfaceTypedDateCast extends DateTimeInterfaceCast
{
    /**
     * The class a date of this type is created as: CarbonImmutable for an interface it implements.
     */
    public static function readAs(string $type): string
    {
        return interface_exists($type) && is_a(CarbonImmutable::class, $type, true) ? CarbonImmutable::class : $type;
    }

    protected function castValue(?string $type, mixed $value): Uncastable|null|DateTimeInterface
    {
        return parent::castValue($type === null ? null : self::readAs($type), $value);
    }
}
