<?php

namespace StrictlyLaravel\TypeScript;

use Spatie\LaravelTypeScriptTransformer\LaravelData\Transformers\DataClassTransformer as LaravelDataClassTransformer;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\EnumKeyedMaps;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\HiddenDataProperties;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\LiteralTypes;

/**
 * laravel-typescript-transformer's transformer for laravel-data classes, with the Overlay's fixes to PHPDoc types and
 * to properties. It replaces the stock one, so every Data class is transformed the same way.
 */
final class DataClassTransformer extends LaravelDataClassTransformer
{
    public function __construct()
    {
        parent::__construct(
            transpilePhpStanTypeToTypeScriptTypeAction: new PhpDocTypeTranspiler([
                new LiteralTypes,
                new EnumKeyedMaps,
            ]),
        );
    }

    protected function classPropertyProcessors(): array
    {
        return [
            new HiddenDataProperties,
            ...parent::classPropertyProcessors(),
        ];
    }
}
