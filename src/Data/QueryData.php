<?php

namespace StrictlyLaravel\Data;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Spatie\LaravelData\Attributes\MergeValidationRules;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Enums\DataTypeKind;
use Spatie\LaravelData\Support\DataConfig;

/**
 * The base class of a GET action's query string, such as list filters (`?statuses[]=active&sort=newest`): type-hint a
 * final subclass on the action (`public function index(ProjectFiltersData $filters)`) and
 *
 * - its route function's `query` option is typed by it (WayfinderTemplates, Problem P-006):
 *   `index({ query: { statuses: ['active'] } })`, so a misspelled key or a value of the wrong type fails `tsc`;
 * - the query string is parsed leniently: an invalid item of a list is dropped and any other key that fails
 *   validation falls back to its default, so an old bookmark or a hand-edited URL never gets a 422 or a 500;
 * - it is an ordinary Data class otherwise, so a Page class can send it back as a prop (`public ProjectFiltersData
 *   $filters`), and the filter UI is typed by the same class it builds the next query from.
 *
 * Every property has a default, since an absent key means "no filter": `null` for a nullable property and `[]` for a
 * list, which are what the route function leaves out of the URL (Problems P-104, P-105). The PHPStan rule
 * `strictly.queryDefault` checks it. Rules returned from `rules()` are merged with the inferred ones, as InputData's
 * are, and a key they reject falls back to its default too.
 */
#[MergeValidationRules]
abstract class QueryData extends Data
{
    public static function from(mixed ...$payloads): static
    {
        if (count($payloads) === 1 && $payloads[0] instanceof Request) {
            return static::fromQuery($payloads[0]);
        }

        return parent::from(...$payloads);
    }

    /**
     * The query of a request, with every invalid list item dropped and every other invalid key left out, so its
     * property's default applies. Never throws a validation exception.
     */
    public static function fromQuery(Request $request): static
    {
        /** @var array<string, mixed> $input */
        $input = $request->query->all();

        foreach (app(DataConfig::class)->getDataClass(static::class)->properties as $property) {
            $key = $property->inputMappedName ?? $property->name;
            $value = $input[$key] ?? null;

            if (! in_array($property->type->kind, [DataTypeKind::Array, DataTypeKind::DataArray], true) || ! is_array($value) || ! array_is_list($value)) {
                continue;
            }

            $input[$key] = array_values(array_filter(
                $value,
                fn (mixed $item): bool => self::passes([...$input, $key => [$item]], $key),
            ));
        }

        $rules = static::getValidationRules($input);

        foreach (Validator::make($input, $rules)->errors()->keys() as $invalid) {
            unset($input[explode('.', $invalid)[0]]);
        }

        // An array payload isn't validated again (only a Request is).
        return parent::from($input);
    }

    /**
     * Whether the input passes every rule of one key and the keys nested in it (`statuses`, `statuses.*`).
     *
     * @param  array<string, mixed>  $input
     */
    private static function passes(array $input, string $key): bool
    {
        $rules = array_filter(
            static::getValidationRules($input),
            fn (string $rule): bool => $rule === $key || str_starts_with($rule, $key.'.'),
            ARRAY_FILTER_USE_KEY,
        );

        return Validator::make($input, $rules)->passes();
    }
}
