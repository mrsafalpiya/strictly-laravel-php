<?php

namespace StrictlyLaravel\PHPStan;

use PHPStan\Reflection\ClassReflection;
use PHPStan\Type\Type;
use Spatie\LaravelData\Data;
use StrictlyLaravel\Inertia\Page;

/**
 * A Data class that would be a JSON endpoint's response on its own, as RouteResponse reads one: a Data class that isn't
 * a Page (Problems P-108, P-109, P-202). JsonResponseRule rejects one in a controller action's nullable or union return
 * type, ClosureRouteRule one anywhere in a closure route's, so the two can't drift.
 */
final readonly class DataResponse
{
    /** Whether a type (one member of a union) is a Data class other than a Page. */
    public static function is(Type $type): bool
    {
        return array_any(
            $type->getObjectClassReflections(),
            static fn (ClassReflection $class): bool => $class->is(Data::class) && ! $class->is(Page::class),
        );
    }
}
