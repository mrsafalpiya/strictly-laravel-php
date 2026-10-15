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
 * the same formats, time zones and DateFormatRule (P-075). StrictInputConfig makes it the global cast for
 * `DateTimeInterface` while the app's config/data.php keeps laravel-data's. Delete this class once laravel-data
 * reads an interface-typed date.
 */
final class InterfaceTypedDateCast extends DateTimeInterfaceCast
{
    protected function castValue(?string $type, mixed $value): Uncastable|null|DateTimeInterface
    {
        if ($type !== null && interface_exists($type) && is_a(CarbonImmutable::class, $type, true)) {
            $type = CarbonImmutable::class;
        }

        return parent::castValue($type, $value);
    }
}
