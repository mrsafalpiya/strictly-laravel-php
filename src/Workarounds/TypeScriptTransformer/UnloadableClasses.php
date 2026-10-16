<?php

namespace StrictlyLaravel\Workarounds\TypeScriptTransformer;

use Spatie\LaravelData\Contracts\BaseData;
use Spatie\StructureDiscoverer\Data\DiscoveredClass;
use Spatie\StructureDiscoverer\Data\DiscoveredEnum;
use Spatie\StructureDiscoverer\Discover;
use Spatie\StructureDiscoverer\Enums\DiscoveredEnumType;
use Spatie\StructureDiscoverer\Enums\DiscoveredStructureType;
use Spatie\TypeScriptTransformer\Attributes\Hidden;
use StrictlyLaravel\Inertia\PageLocations;
use Throwable;

/**
 * Problem P-073 (docs/problems.md). Upstream: no upstream issue filed (typescript-transformer 3.3's
 * `DiscoverTypesAction` catches any error loading a discovered class and skips the class).
 *
 * A Data class saved mid-edit so that it parses but can't be loaded (`final class ProjectData extends Dat`, a missing
 * interface or trait) is dropped from `@/generated` while generation still succeeds, so every component that uses its
 * type breaks. Loading the classes typescript-transformer would transform first lets `strictly:generate` fail instead,
 * naming each class, its file and the error, and leave the last good output in place, which is what the Vite plugin
 * relies on (the parse-error case is UnparsableFiles').
 *
 * A class that can't be loaded can't be asked what it is, so its source decides: a backed enum, a class whose known
 * parents or interfaces make it a Data class (Page classes are Data classes), or any class in the Data namespace
 * (App\Data, which `strictly.dataNamespace` keeps for Data classes alone) or in App\Pages, since a half-typed parent
 * class (`extends Dat`) tells nothing (outside those namespaces such a class is still skipped, P-183). Any other class
 * that can't be loaded, such as a service provider for a dev-only package a production install leaves out, and any
 * class marked `#[Hidden]`, is skipped as before. Once upstream reports such a class instead of skipping it, delete
 * this one.
 */
final class UnloadableClasses
{
    private const string DATA_NAMESPACE = 'App\\Data\\';

    /**
     * Every class or enum in the given directories that typescript-transformer would transform but can't load, as
     * `<path>: <class> can't be loaded: <error>` with the path relative to $basePath.
     *
     * @param  list<string>  $directories
     * @return list<string>
     */
    public static function in(array $directories, string $basePath): array
    {
        $directories = array_values(array_filter($directories, is_dir(...)));

        if ($directories === []) {
            return [];
        }

        /** @var list<DiscoveredClass|DiscoveredEnum> $structures */
        $structures = Discover::in(...$directories)
            ->types(DiscoveredStructureType::ClassDefinition, DiscoveredStructureType::Enum)
            ->full()
            ->get();

        $errors = [];

        foreach ($structures as $structure) {
            $class = $structure->getFcqn();
            $error = self::loadError($class);

            if ($error !== null && self::wouldBeTransformed($structure)) {
                $relative = str_starts_with($structure->file, $basePath.DIRECTORY_SEPARATOR) ? substr($structure->file, strlen($basePath) + 1) : $structure->file;
                $errors[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative).": {$class} can't be loaded: {$error}";
            }
        }

        sort($errors);

        return $errors;
    }

    /**
     * Why a class can't be loaded, or null when it can.
     */
    private static function loadError(string $class): ?string
    {
        try {
            // A file that loads but declares no such class is reported as ReflectionClass reports it.
            return class_exists($class) ? null : "Class \"{$class}\" does not exist";
        } catch (Throwable $e) {
            return $e->getMessage();
        }
    }

    private static function wouldBeTransformed(DiscoveredClass|DiscoveredEnum $structure): bool
    {
        foreach ($structure->attributes as $attribute) {
            if (ltrim($attribute->class, '\\') === Hidden::class) {
                return false;
            }
        }

        if ($structure instanceof DiscoveredEnum) {
            return $structure->type !== DiscoveredEnumType::Unit;
        }

        $class = $structure->getFcqn();

        return str_starts_with($class, self::DATA_NAMESPACE) || str_starts_with($class, PageLocations::NAMESPACE)
            || self::isData($structure);
    }

    /**
     * Whether a parent class or interface the class declares, directly or through the app's own classes and
     * interfaces, makes it a Data class (a Page class is one too).
     */
    private static function isData(DiscoveredClass $structure): bool
    {
        foreach ([...$structure->extendsChain ?? [], ...$structure->implementsChain ?? []] as $name) {
            try {
                if (is_a(ltrim($name, '\\'), BaseData::class, true)) {
                    return true;
                }
            } catch (Throwable) {
                // An ancestor that can't be loaded tells nothing about the class.
            }
        }

        return false;
    }
}
