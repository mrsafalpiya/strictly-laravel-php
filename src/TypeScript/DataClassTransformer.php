<?php

namespace StrictlyLaravel\TypeScript;

use Spatie\LaravelTypeScriptTransformer\LaravelData\Transformers\DataClassTransformer as LaravelDataClassTransformer;
use StrictlyLaravel\Routes\RouteKeys;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\EnumKeyedMaps;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\HiddenDataProperties;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\LiteralTypes;

/**
 * laravel-typescript-transformer's transformer for laravel-data classes, with the Overlay's fixes to PHPDoc types and
 * to properties, and a model's route keys branded by the model (RouteKeyTypes). It replaces the stock one, so every Data class is transformed the same way.
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
            new DateOnlyTypes,
            ...parent::classPropertyProcessors(),
            // Last, on the final type: brands its number or string (`?int` → `RouteKey<'Project', number> | null`).
            new RouteKeyTypes(fn (): RouteKeys => RouteKeys::of(app('router'))),
        ];
    }
}
