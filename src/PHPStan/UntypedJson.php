<?php

namespace StrictlyLaravel\PHPStan;

use ArrayObject;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Contracts\Support\Jsonable;
use JsonSerializable;
use PHPStan\Reflection\ClassReflection;
use PHPStan\Reflection\ReflectionProvider;
use PHPStan\Type\Type;
use Spatie\LaravelData\Data;
use stdClass;

/**
 * What Laravel answers as JSON that no Data class types, when a route returns it (Problems P-108, P-179): an array or
 * other iterable, or an object Laravel's router serialises (Router::toResponse()), such as a Collection, laravel-data's
 * DataCollection, a paginator, a model or an API resource, that is neither a Data class nor a response. JsonResponseRule
 * reads controller actions with it, ClosureRouteRule closure routes, so the two can't drift.
 */
final readonly class UntypedJson
{
    /** What Laravel's router answers as JSON when a route returns it (Router::toResponse()). */
    private const array JSON = [Arrayable::class, Jsonable::class, JsonSerializable::class, ArrayObject::class, stdClass::class];

    private const string SYMFONY_RESPONSE = 'Symfony\Component\HttpFoundation\Response';

    /** Whether Laravel answers a value of this type (one member of a union) as JSON that isn't a Data class. */
    public static function is(Type $type, ReflectionProvider $reflection): bool
    {
        $classes = $type->getObjectClassReflections();

        if ($classes === []) {
            return $type->isIterable()->yes();
        }

        return array_any($classes, static fn (ClassReflection $class): bool => ! $class->is(Data::class)
            && ! $class->is(self::SYMFONY_RESPONSE)
            && array_any(self::JSON, static fn (string $json): bool => $reflection->hasClass($json) && $class->is($json)));
    }
}
