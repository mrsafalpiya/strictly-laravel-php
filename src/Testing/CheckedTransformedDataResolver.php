<?php

namespace StrictlyLaravel\Testing;

use Spatie\LaravelData\Resolvers\TransformedDataResolver;
use Spatie\LaravelData\Support\DataProperty;
use Spatie\LaravelData\Support\Transformation\TransformationContext;

/**
 * laravel-data's serialisation, checking every route-key property it sends (RouteKeyChecks). Bound in feature tests
 * only.
 */
final class CheckedTransformedDataResolver extends TransformedDataResolver
{
    protected function resolvePropertyValue(
        DataProperty $property,
        mixed $value,
        TransformationContext $currentContext,
        ?TransformationContext $fieldContext,
    ): mixed {
        $resolved = parent::resolvePropertyValue($property, $value, $currentContext, $fieldContext);

        RouteKeyChecks::check($property->className, $property->name, $resolved);

        return $resolved;
    }
}
