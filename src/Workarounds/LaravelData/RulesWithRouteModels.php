<?php

namespace StrictlyLaravel\Workarounds\LaravelData;

use Illuminate\Support\Arr;
use LogicException;
use Spatie\LaravelData\Resolvers\DataValidationRulesResolver;
use Spatie\LaravelData\Support\DataClass;
use Spatie\LaravelData\Support\Validation\ValidationContext;
use Spatie\LaravelData\Support\Validation\ValidationPath;
use StrictlyLaravel\Data\InputData;

/**
 * Problem P-126: laravel-data's DataValidationRulesResolver, but an input's `rules()` (an InputData's, nested ones
 * included) receives the route's bound models for its model-typed parameters (RouteModelArguments). Every other Data
 * class's rules() are called as laravel-data calls them. The service provider binds it in place of laravel-data's
 * resolver, since laravel-data has no per-class hook for calling rules().
 */
final class RulesWithRouteModels extends DataValidationRulesResolver
{
    /**
     * @param  array<array-key, mixed>  $fullPayload
     * @return array<array-key, mixed>
     */
    protected function resolveOverwrittenRules(DataClass $class, array $fullPayload, ValidationPath $path): array
    {
        if (! $class->hasDynamicValidationRules || ! is_subclass_of($class->name, InputData::class)) {
            return parent::resolveOverwrittenRules($class, $fullPayload, $path);
        }

        // As laravel-data builds it.
        $context = new ValidationContext(
            $path->isRoot() ? $fullPayload : Arr::get($fullPayload, $path->get(), []),
            $fullPayload,
            $path,
        );

        $rules = app()->call([$class->name, 'rules'], [...RouteModelArguments::of($class->name, 'rules'), 'context' => $context]);

        if (! is_array($rules)) {
            throw new LogicException("{$class->name}::rules() returned ".get_debug_type($rules).', not an array of rules.');
        }

        return $rules;
    }
}
