<?php

namespace StrictlyLaravel\Testing;

use Closure;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Validation\Factory;
use Illuminate\Validation\Validator;
use Stringable;

/**
 * The fields the app validated while handling the current request (Problem P-113): every key of every rule set a
 * validator was made with, an input class's inferred rules and its own `rules()` alike, plus the field a `confirmed`
 * rule compares with (`password` → `password_confirmation`). ContractRecorder checks them against the action's input
 * type, so a rule can't require a key the typed form never sends.
 *
 * Validators are made through the application's validation factory; listen() wraps its resolver, keeping any the app
 * set.
 */
final class ValidatedFields
{
    /** @var list<string> */
    private static array $fields = [];

    public static function listen(Factory $validation): void
    {
        /** @var Closure|null $resolver */
        $resolver = (fn (): ?Closure => $this->resolver)->call($validation);

        $validation->resolver(function (Translator $translator, array $data, array $rules, array $messages, array $attributes) use ($resolver): Validator {
            foreach ($rules as $key => $rule) {
                array_push(self::$fields, (string) $key, ...self::confirmationFields((string) $key, $rule));
            }

            $validator = $resolver === null
                ? new Validator($translator, $data, $rules, $messages, $attributes)
                : $resolver($translator, $data, $rules, $messages, $attributes);

            assert($validator instanceof Validator);

            return $validator;
        });
    }

    /** Forget the fields of the previous request: called as a request reaches its route. */
    public static function reset(): void
    {
        self::$fields = [];
    }

    /**
     * @return list<string>
     */
    public static function fields(): array
    {
        return array_values(array_unique(self::$fields));
    }

    /**
     * `confirmed` also validates `{field}_confirmation`, and `confirmed:other` validates `other`.
     *
     * @return list<string>
     */
    private static function confirmationFields(string $key, mixed $rule): array
    {
        $rules = is_string($rule) ? explode('|', $rule) : (is_array($rule) ? $rule : [$rule]);
        $fields = [];

        foreach ($rules as $item) {
            $item = is_string($item) || $item instanceof Stringable ? (string) $item : null;

            if ($item === 'confirmed') {
                $fields[] = $key.'_confirmation';
            } elseif ($item !== null && str_starts_with($item, 'confirmed:')) {
                $fields[] = substr($item, strlen('confirmed:'));
            }
        }

        return $fields;
    }
}
