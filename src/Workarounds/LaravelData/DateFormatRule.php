<?php

namespace StrictlyLaravel\Workarounds\LaravelData;

use Closure;
use DateTime;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;
use ReflectionClass;
use ValueError;

/**
 * Validates a datetime property, or every item of a list of them, against the formats its DateTimeInterfaceCast reads
 * (Problem P-075): the value must be text the cast reads in one of them, as it reads it (`2026-10-14T09:30:00Z` is read
 * by DATE_ATOM's `P`), naming a real date and time: `2026-02-30`, which the cast would roll over to March, is rejected.
 * A date object is accepted as it is, since the cast keeps it. Added by DateFormatRuleInferrer; the error is Laravel's
 * own `date_format` message, naming the first format.
 *
 * A rule object rather than Laravel's `date_format:` string, which also requires the value to format back to itself
 * (rejecting the `Z` the cast accepts), and whose list of formats would split a format containing a comma
 * (DATE_RFC2822).
 */
final readonly class DateFormatRule implements ValidationRule
{
    /**
     * @param  non-empty-list<string>  $formats
     * @param  class-string<DateTime|DateTimeImmutable>  $type  the class the cast creates, whose createFromFormat()
     *                                                          reads the value: Carbon's reads more formats than PHP's
     *                                                          own (`c`, an `A` before the hour)
     */
    private function __construct(
        private array $formats,
        private bool $eachItem,
        private string $type,
    ) {}

    /**
     * @param  non-empty-list<string>  $formats
     */
    public static function of(array $formats, string $type = DateTime::class): self
    {
        return new self($formats, false, self::creatable($type));
    }

    /**
     * @param  non-empty-list<string>  $formats
     */
    public static function ofEachItem(array $formats, string $type = DateTime::class): self
    {
        return new self($formats, true, self::creatable($type));
    }

    /**
     * @return class-string<DateTime|DateTimeImmutable>
     */
    private static function creatable(string $type): string
    {
        // An interface is read as InterfaceTypedDateCast reads it (P-189); one it can't read fails every value anyway.
        $type = InterfaceTypedDateCast::readAs($type);

        if (is_a($type, DateTime::class, true) || is_a($type, DateTimeImmutable::class, true)) {
            return (new ReflectionClass($type))->isAbstract() ? DateTime::class : $type;
        }

        return DateTime::class;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! $this->eachItem) {
            if (! $this->accepts($value)) {
                $fail('validation.date_format')->translate(['format' => $this->formats[0]]);
            }

            return;
        }

        if (! is_array($value)) {
            return;
        }

        foreach ($value as $index => $item) {
            if (! $this->accepts($item)) {
                $fail('validation.date_format')->translate(['attribute' => "{$attribute}.{$index}", 'format' => $this->formats[0]]);

                return;
            }
        }
    }

    private function accepts(mixed $value): bool
    {
        if ($value instanceof DateTimeInterface) {
            return true;
        }

        if (! is_string($value) && ! is_numeric($value)) {
            return false;
        }

        // As DateTimeInterfaceCast reads it: nanoseconds truncated to microseconds, then each format in turn.
        $value = (string) preg_replace('/(\.\d{6})\d+/', '$1', (string) $value);

        foreach ($this->formats as $format) {
            try {
                $date = $this->type::createFromFormat($format, $value);
            } catch (ValueError|InvalidArgumentException) {
                // An invalid format, or Carbon's strict mode refusing the value.
                continue;
            }

            // A warning is an impossible date or time the cast would roll over ("The parsed date was invalid").
            $errors = $this->type::getLastErrors();

            if ($date !== false && ($errors === false || $errors['warning_count'] + $errors['error_count'] === 0)) {
                return true;
            }
        }

        return false;
    }
}
