<?php

namespace StrictlyLaravel\TypeScript;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\File;
use Spatie\LaravelTypeScriptTransformer\LaravelData\LaravelDataTypeScriptTransformerExtension;
use Spatie\LaravelTypeScriptTransformer\LaravelData\Transformers\DataClassTransformer as LaravelDataClassTransformer;
use Spatie\TypeScriptTransformer\Transformers\EnumTransformer;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfig;
use Spatie\TypeScriptTransformer\TypeScriptTransformerConfigFactory;
use Spatie\TypeScriptTransformer\Writers\FlatModuleWriter;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\InertiaPropWrappers;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\PaginatorTypes;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\UnmappedClassTypes;

/**
 * The typescript-transformer v3 configuration (ADR-0003) that turns the app's PHP into resources/js/generated/index.ts,
 * imported as `@/generated`. Nothing is registered by hand: every laravel-data class and every backed enum anywhere
 * in app/ becomes a TypeScript type, and every backed enum's values are exported in case order as `enumValues`.
 */
final class TypeGeneration
{
    /** Relative to resources/js; gitignored by the installer and regenerated wherever types are needed. */
    public const string OUTPUT_DIRECTORY = 'generated';

    public static function outputDirectory(): string
    {
        return resource_path('js/'.self::OUTPUT_DIRECTORY);
    }

    public static function config(Application $app): TypeScriptTransformerConfig
    {
        // Problem P-064: the transformer refuses to run without its output directory, which a fresh clone lacks
        // because it is gitignored.
        File::ensureDirectoryExists(self::outputDirectory());

        $factory = TypeScriptTransformerConfigFactory::create()
            ->outputDirectory(self::outputDirectory())
            ->extension(new LaravelDataTypeScriptTransformerExtension)
            ->replaceTransformer(LaravelDataClassTransformer::class, new DataClassTransformer)
            ->transformer(new EnumTransformer)
            ->transformDirectories($app->path())
            ->provider(new PaginatorTypes)
            ->provider(new EnumValuesProvider([$app->path()]))
            ->providedVisitorHook(new InertiaPropWrappers)
            ->connectedVisitorHook(new UnmappedClassCheck)
            ->writer(new FlatModuleWriter('index.ts'))
            // Always write the file, so a deleted output is regenerated even when the PHP is unchanged.
            ->withoutManifest();

        UnmappedClassTypes::apply($factory);

        return $factory->get();
    }
}
