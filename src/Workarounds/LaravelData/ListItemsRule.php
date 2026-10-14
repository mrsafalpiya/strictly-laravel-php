<?php

namespace StrictlyLaravel\Workarounds\LaravelData;

use BackedEnum;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates every item of a typed list property (`/** @var list<IssueStatus> *\/ array $statuses`), which laravel-data
 * leaves unvalidated (Problem P-022): an item of the given scalar type or backed enum, or one of the given literal
 * values. Added by ListItemsRuleInferrer and LiteralRuleInferrer; the error names the first invalid item,
 * `The statuses.1 value is invalid.`
 */
final readonly class ListItemsRule implements ValidationRule
{
    /**
     * @param  'int'|'float'|'string'|'bool'|class-string<BackedEnum>|null  $type
     * @param  list<string|int|float>|null  $values  the literals an item may be, instead of a type
     */
    private function __construct(
        private ?string $type,
        private ?array $values,
    ) {}

    /**
     * @param  'int'|'float'|'string'|'bool'|class-string<BackedEnum>  $type
     */
    public static function ofType(string $type): self
    {
        return new self($type, null);
    }

    /**
     * @param  list<string|int|float>  $values
     */
    public static function oneOf(array $values): self
    {
        return new self(null, $values);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $index => $item) {
            if (! $this->accepts($item)) {
                $fail("The {$attribute}.{$index} value is invalid.");

                return;
            }
        }
    }

    private function accepts(mixed $item): bool
    {
        if ($this->values !== null) {
            // Compared as strings, as Laravel's `in:` rule does, since a form-encoded request sends every value as one.
            return (is_string($item) || is_int($item) || is_float($item))
                && in_array((string) $item, array_map(strval(...), $this->values), true);
        }

        return match ($this->type) {
            'int' => is_int($item) || (is_string($item) && preg_match('/^-?\d+$/', $item) === 1),
            'float' => is_int($item) || is_float($item) || (is_string($item) && is_numeric($item)),
            'string' => is_string($item),
            'bool' => is_bool($item) || in_array($item, [0, 1, '0', '1', 'true', 'false'], true),
            null => true,
            default => (is_string($item) || is_int($item)) && $this->type::tryFrom($item) !== null,
        };
    }
}
