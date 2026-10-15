<?php

namespace StrictlyLaravel\Data;

use Spatie\LaravelData\Attributes\MergeValidationRules;
use Spatie\LaravelData\Data;

/**
 * The base class of every request payload: type-hint a final subclass on a controller action, and that one class
 * validates the request and types the form. The action's Wayfinder route is branded with it (RouteInput), so
 * `useRouteForm(route, defaults)`, `submit(route, data)` and their validation errors take their keys from it.
 *
 * - The rules inferred from the property types match the generated TypeScript (Workarounds\LaravelData): non-null is
 *   required (`present` for arrays, so `[]` is accepted), `int` is `integer`, every item of a typed list is
 *   validated, and a PHPDoc literal union is `in:`.
 * - Rules returned from `rules()` are merged with the inferred ones, not replacing them (Problem P-017). Plain
 *   laravel-data replaces them per key, so adding `'unique:projects'` for `$slug` would drop `required|string`.
 * - Declare `public static function authorize(...): bool` (its parameters are injected) to authorize the request: it
 *   runs before validation, so a user who may not submit gets a 403, not a 422 listing the fields (Problem P-030).
 *   The PHPStan rule `strictly.authorizeInInput` rejects authorizing in the action's body instead.
 *
 * The PHPStan rule `strictly.inputData` requires every Data class an action takes to extend this class, and every Data
 * class with `rules()` nested in an input too: laravel-data reads #[MergeValidationRules] from each nested class, so a
 * plain one's `rules()` replace its inferred ones (Problem P-099).
 */
#[MergeValidationRules]
abstract class InputData extends Data {}
