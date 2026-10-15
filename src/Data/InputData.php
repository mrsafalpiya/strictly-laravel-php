<?php

namespace StrictlyLaravel\Data;

use Spatie\LaravelData\Attributes\MergeValidationRules;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataPipeline;
use Spatie\LaravelData\DataPipes\AuthorizedDataPipe;
use StrictlyLaravel\Workarounds\LaravelData\AuthorizeWithRouteModels;

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
 * - A model-typed parameter of `authorize()` or `rules()` receives the model the route binds to the parameter of the
 *   same name (`authorize(Comment $comment)` for `{comment}`), never a new, empty one (Problem P-126): a parameter the
 *   route has no such model for throws, naming it. So does validating such an input outside a request to its route
 *   (`validate()` from a command or a unit test, Problem P-155). Every other parameter is resolved from the container.
 *   The PHPStan rule `strictly.routeModelParameter` rejects a model parameter there on any other Data class.
 *
 * The PHPStan rule `strictly.inputData` requires every Data class an action takes to extend this class, and every Data
 * class with `rules()` nested in an input too: laravel-data reads #[MergeValidationRules] from each nested class, so a
 * plain one's `rules()` replace its inferred ones (Problem P-099).
 */
#[MergeValidationRules]
abstract class InputData extends Data
{
    public static function pipeline(): DataPipeline
    {
        return parent::pipeline()->replace(AuthorizedDataPipe::class, AuthorizeWithRouteModels::class);
    }
}
