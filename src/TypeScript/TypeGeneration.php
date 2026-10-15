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
use StrictlyLaravel\Inertia\PageLocations;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\DuplicateTypeNames;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\InertiaPropWrappers;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\MixedAsUnknown;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\PaginatorTypes;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\UnmappedClassCheck;
use StrictlyLaravel\Workarounds\TypeScriptTransformer\UnmappedClassTypes;

/**
 * The typescript-transformer v3 configuration (ADR-0003) that turns the app's PHP into resources/js/generated/index.ts,
 * imported as `@/generated`. Nothing is registered by hand: every laravel-data class and every backed enum anywhere
 * in app/ becomes a TypeScript type, every backed enum's values are exported in case order as `enumValues`, every
 * Page class becomes `PageProps<'component'>`, checked against its page component, the shared props and flash
 * Data classes become Inertia's global config typing, and `import.meta.env` is typed from .env.example (ViteEnvTypes).
 *
 * Every PHP type form generates the type that tells the truth: the fixes for typescript-transformer's defects each live
 * in their own class under StrictlyLaravel\Workarounds\TypeScriptTransformer (citing their Problem and upstream issue;
 * among them, a PHP class with no TypeScript type fails generation, UnmappedClassCheck, and so do two classes that
 * generate the same type name, DuplicateTypeNames), and `#[DateOnly]` dates get their own type (DateOnlyTypes).
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

        $pages = new PageTransformer;

        $factory = TypeScriptTransformerConfigFactory::create()
            ->outputDirectory(self::outputDirectory())
            ->extension(new LaravelDataTypeScriptTransformerExtension)
            ->replaceTransformer(LaravelDataClassTransformer::class, new DataClassTransformer)
            ->transformer(new EnumTransformer)
            ->transformDirectories($app->path())
            ->provider(new PaginatorTypes)
            ->provider(new DateOnlyTypes)
            ->provider(new EnumValuesProvider([$app->path()]))
            ->provider(new InertiaPagesProvider(PageLocations::classesDirectory(), PageLocations::componentsDirectory()))
            ->provider(new InertiaConfigProvider([$app->path()]))
            ->provider(new ViteEnvTypes($app->basePath('.env.example')))
            ->providedVisitorHook(new InertiaPropWrappers)
            ->providedVisitorHook(new MixedAsUnknown)
            ->connectedVisitorHook(new RawInertiaPropCheck)
            ->connectedVisitorHook(new UnmappedClassCheck)
            ->writer(new DuplicateTypeNames(new FlatModuleWriter('index.ts')))
            // Always write the file, so a deleted output is regenerated even when the PHP is unchanged.
            ->withoutManifest();

        UnmappedClassTypes::apply($factory);

        // Last, so it comes first in the chain: a Page class's props are transformed by every other transformer.
        $config = $factory->prependTransformer($pages)->get();
        $pages->delegateTo($config->transformers);

        return $config;
    }
}
