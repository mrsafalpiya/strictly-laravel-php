<?php

namespace StrictlyLaravel\TypeScript;

use FilesystemIterator;
use LogicException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\References\CustomReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TransformedProviders\ActionAwareTransformedProvider;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProviderActions;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIdentifier;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptObject;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptProperty;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptRaw;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptReference;
use SplFileInfo;
use StrictlyLaravel\Inertia\Page;
use StrictlyLaravel\Inertia\PageLocations;

/**
 * Ties every Page class to its page component (Problems P-009 and P-010):
 *
 *     export type InertiaPages = { 'settings/profile': SettingsProfilePage; … };
 *     export type PageComponent = keyof InertiaPages;
 *     export type PageProps<K extends PageComponent> = InertiaPages[K];
 *     export type __PageComponentCheck = [CheckPage<'settings/profile', OnlySentProps<'settings/profile', typeof import('@/pages/settings/profile').default>>, …];
 *     export type __PageFileCheck = [HasPageClass<'settings/profile'>, …];
 *
 * The checks make `tsc` fail when a Page class has no page component file, when the component declares a prop the
 * class doesn't send (even an optional one, which would otherwise always be missing), and when a page component file
 * has no Page class. The page types themselves come from the
 * directory scan, named by PageTransformer.
 */
final class InertiaPagesProvider implements ActionAwareTransformedProvider, TransformedProvider
{
    private const string GROUP = 'strictly-pages';

    private TransformedProviderActions $actions;

    /** The extensions of page component files, as the Base Kit's config/inertia.php lists them for React. */
    private const array COMPONENT_EXTENSIONS = ['tsx', 'ts', 'jsx', 'js'];

    /** How a page component is imported, as the Base Kit's `@/` alias for resources/js resolves it. */
    private const string COMPONENTS_IMPORT_PREFIX = '@/pages/';

    /**
     * @param  string  $pagesDirectory  where the Page classes live (app/Pages)
     * @param  string  $componentsDirectory  where the page components live (resources/js/pages)
     */
    public function __construct(
        private readonly string $pagesDirectory,
        private readonly string $componentsDirectory,
    ) {}

    public function setActions(TransformedProviderActions $actions): void
    {
        $this->actions = $actions;
    }

    /**
     * @return list<Transformed>
     */
    public function provide(): array
    {
        $pages = $this->pages();

        return [...$this->pageMap($pages), ...$this->componentCheck($pages), ...$this->pageFileCheck()];
    }

    /**
     * Every concrete Page class, keyed by its page component's name and sorted by it.
     *
     * @return array<string, class-string<Page>>
     */
    private function pages(): array
    {
        if (! is_dir($this->pagesDirectory)) {
            return [];
        }

        $pages = [];

        foreach ($this->actions->discoverTypesAction->execute([$this->pagesDirectory]) as $node) {
            $class = $node->getName();

            if ($node->isAbstract() || ! is_subclass_of($class, Page::class)) {
                continue;
            }

            $component = PageLocations::componentFor($class);

            if (isset($pages[$component])) {
                throw new LogicException("Both {$pages[$component]} and {$class} render the page component '{$component}'.");
            }

            $pages[$component] = $class;
        }

        ksort($pages);

        return $pages;
    }

    /**
     * @param  array<string, class-string<Page>>  $pages
     * @return list<Transformed>
     */
    private function pageMap(array $pages): array
    {
        $properties = [];

        foreach ($pages as $component => $class) {
            $properties[] = new TypeScriptProperty($component, new TypeScriptReference(new ClassStringReference($class)));
        }

        return [
            new Transformed(
                new TypeScriptAlias(new TypeScriptIdentifier('InertiaPages'), new TypeScriptObject($properties)),
                new CustomReference(self::GROUP, 'pages'),
                [],
                true,
            ),
            new Transformed(
                new TypeScriptRaw(implode("\n", [
                    '/** The name of every page component that has a Page class. */',
                    'export type PageComponent = keyof %InertiaPages%;',
                    "/** The props a page component receives, from its Page class's constructor: `PageProps<'settings/profile'>`. */",
                    'export type PageProps<K extends PageComponent> = %InertiaPages%[K];',
                ]), references: ['InertiaPages' => new CustomReference(self::GROUP, 'pages')]),
                new CustomReference(self::GROUP, 'helpers'),
                [],
                false,
            ),
        ];
    }

    /**
     * @param  array<string, class-string<Page>>  $pages
     * @return list<Transformed>
     */
    private function componentCheck(array $pages): array
    {
        if ($pages === []) {
            return [];
        }

        $checks = array_map(
            fn (string $component): string => "    CheckPage<'{$component}', OnlySentProps<'{$component}', typeof import('".self::COMPONENTS_IMPORT_PREFIX."{$component}').default>>,",
            array_keys($pages),
        );

        return [new Transformed(
            new TypeScriptRaw(implode("\n", [
                "// Fails to compile when a Page class's page component file is missing, or the component declares a prop the class",
                "// doesn't send, even an optional one: it then stands in as `{ unsentProps: 'prop' | … }`, which isn't a component.",
                'type OnlySentProps<K extends PageComponent, C> = C extends (props: infer P) => unknown',
                '    ? [Exclude<keyof P, keyof %InertiaPages%[K]>] extends [never]',
                '        ? C',
                '        : { unsentProps: Exclude<keyof P, keyof %InertiaPages%[K]> }',
                '    : C;',
                'type CheckPage<K extends PageComponent, C extends (props: %InertiaPages%[K]) => unknown> = [K, C];',
                'export type __PageComponentCheck = [',
                ...$checks,
                '];',
            ]), references: ['InertiaPages' => new CustomReference(self::GROUP, 'pages')]),
            new CustomReference(self::GROUP, 'component-check'),
            [],
            false,
        )];
    }

    /**
     * @return list<Transformed>
     */
    private function pageFileCheck(): array
    {
        $checks = array_map(
            fn (string $component): string => "    HasPageClass<'{$component}'>,",
            $this->componentFiles(),
        );

        if ($checks === []) {
            return [];
        }

        return [new Transformed(
            new TypeScriptRaw(implode("\n", [
                '// Fails to compile when a page component file has no Page class (`php artisan make:page <component>` creates both).',
                'type HasPageClass<K extends PageComponent> = K;',
                'export type __PageFileCheck = [',
                ...$checks,
                '];',
            ])),
            new CustomReference(self::GROUP, 'file-check'),
            [],
            false,
        )];
    }

    /**
     * The page component name of every file under the components directory, sorted.
     *
     * @return list<string>
     */
    private function componentFiles(): array
    {
        if (! is_dir($this->componentsDirectory)) {
            return [];
        }

        $components = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->componentsDirectory, FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! in_array($file->getExtension(), self::COMPONENT_EXTENSIONS, true)
                || str_ends_with($file->getFilename(), '.d.ts')) {
                continue;
            }

            $relative = substr($file->getPathname(), strlen(rtrim($this->componentsDirectory, '/\\')) + 1);
            $components[] = str_replace('\\', '/', substr($relative, 0, -strlen($file->getExtension()) - 1));
        }

        sort($components);

        return array_values(array_unique($components));
    }
}
