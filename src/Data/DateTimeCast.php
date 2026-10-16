<?php

namespace StrictlyLaravel\Data;

use DateTimeInterface;
use Spatie\LaravelData\Casts\Uncastable;
use StrictlyLaravel\Workarounds\LaravelData\InterfaceTypedDateCast;

/**
 * The cast of every datetime property without a cast of its own (`public CarbonImmutable $startsAt`, each item of a
 * `list<CarbonImmutable>`), in input, query and output classes alike: laravel-data's DateTimeInterfaceCast, which also
 * reads the timestamp JavaScript's `toISOString()` builds, `2026-10-14T09:30:00.000Z` (Problem P-173).
 *
 * A datetime is a `string` in TypeScript, so `new Date().toISOString()` is what a form most naturally sends, and its
 * milliseconds don't fit DATE_ATOM, laravel-data's default `data.date_format`: every app would get a 422 for it once.
 * So {@see TO_ISO_STRING} is read after the formats `data.date_format` lists, by this cast and by the format rule
 * DateFormatRuleInferrer infers from {@see formats()}, which accepts exactly what the cast reads. The app's
 * config/data.php is left alone: StrictInputConfig makes this the global cast for `DateTimeInterface` in place of
 * laravel-data's. A date is still sent in the first format `data.date_format` lists (DateTimeInterfaceTransformer).
 *
 * A property with a format of its own (`#[WithCast(DateTimeInterfaceCast::class, format: …)]`, or `#[DateFormat]`,
 * whose rule replaces the inferred one) reads only that format: the app chose it. An app that casts dates with a class
 * of its own keeps it, and reads what that class reads.
 */
final class DateTimeCast extends InterfaceTypedDateCast
{
    /** `toISOString()`'s format: milliseconds, then `Z`, which PHP reads as UTC. */
    public const string TO_ISO_STRING = 'Y-m-d\TH:i:s.vP';

    /**
     * The formats this cast reads a date in, in the order it tries them: its own format, or else the formats of
     * `data.date_format` followed by `toISOString()`'s.
     *
     * @return list<string>
     */
    public function formats(): array
    {
        $format = $this->format ?? config('data.date_format');
        $formats = array_values(array_filter(is_array($format) ? $format : [$format], is_string(...)));

        if ($this->format !== null || in_array(self::TO_ISO_STRING, $formats, true)) {
            return $formats;
        }

        return [...$formats, self::TO_ISO_STRING];
    }

    protected function castValue(?string $type, mixed $value): Uncastable|null|DateTimeInterface
    {
        // DateTimeInterfaceCast reads `$this->format`, falling back to `data.date_format` when it is null, so the
        // formats are set for this one read. The cast is shared by every property, so its own format is restored.
        $format = $this->format;
        $this->format = $this->formats();

        try {
            return parent::castValue($type, $value);
        } finally {
            $this->format = $format;
        }
    }
}
