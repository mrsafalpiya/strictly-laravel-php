<?php

namespace StrictlyLaravel\Data;

use Attribute;
use Spatie\LaravelData\Attributes\WithCastAndTransformer;

/**
 * Problem P-023: marks a `CarbonInterface` property of a Data class as a calendar date, such as a due date, with no
 * time or timezone. It is read and sent as `"2026-10-14"`, anything else is a validation error on the field, and it
 * is generated as the TypeScript type `DateOnly` (`YYYY-MM-DD`). Every other date is an ISO-8601 string.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class DateOnly extends WithCastAndTransformer
{
    public function __construct()
    {
        parent::__construct(DateOnlyCast::class);
    }
}
