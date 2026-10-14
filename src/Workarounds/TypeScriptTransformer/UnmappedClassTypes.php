<?php

namespace StrictlyLaravel\Workarounds\TypeScriptTransformer;

use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptRaw;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptString;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;

/**
 * Problem P-061 (docs/problems.md). Upstream: no upstream issue filed (laravel-typescript-transformer 3.3 maps only
 * `CarbonInterface`).
 *
 * typescript-transformer generates `undefined` for a PHP class it has no type for. Two classes a Data class
 * legitimately holds get their real browser type here: an `UploadedFile` (and subclasses) is a `File`, and any
 * `DateTimeInterface` is serialised by laravel-data as an ISO-8601 string. Every other unmapped class fails generation
 * (StrictlyLaravel\TypeScript\UnmappedClassCheck). Delete this class once upstream maps both.
 */
final class UnmappedClassTypes
{
    public static function apply(TypeScriptTransformerConfigFactory $factory): void
    {
        $factory
            ->replaceType(UploadedFile::class, new TypeScriptRaw('File'))
            ->replaceType(DateTimeInterface::class, new TypeScriptString);
    }
}
