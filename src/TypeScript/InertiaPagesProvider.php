<?php

namespace StrictlyLaravel\TypeScript;

use LogicException;
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
use StrictlyLaravel\Inertia\Page;

/**
 * Ties every Page class to its page component (Problems P-009 and P-010):
 *
 *     export type InertiaPages = { 'settings/profile': SettingsProfilePage; … };
 *     export type PageComponent = keyof InertiaPages;
 *     export type PageProps<K extends PageComponent> = InertiaPages[K];
 *     export type __PageComponentCheck = [CheckPage<'settings/profile', typeof import('@/pages/settings/profile').default>, …];
 *
 * The check makes `tsc` fail when a Page class has no page component file, or when the component asks for a prop the
 * class doesn't send. The page types themselves come from the directory scan, named by PageTransformer.
 */
final class InertiaPagesProvider implements ActionAwareTransformedProvider, TransformedProvider
{
    private const string GROUP = 'strictly-pages';

    private TransformedProviderActions $actions;

    public function __construct(
        private readonly string $pagesDirectory,
        private readonly string $componentsImportPrefix = '@/pages/',
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

        return [...$this->pageMap($pages), ...$this->componentCheck($pages)];
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

            $component = Page::componentFor($class);

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
            fn (string $component): string => "    CheckPage<'{$component}', typeof import('{$this->componentsImportPrefix}{$component}').default>,",
            array_keys($pages),
        );

        return [new Transformed(
            new TypeScriptRaw(implode("\n", [
                "// Fails to compile when a Page class's page component file is missing, or the component asks for a prop the class doesn't send.",
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
}
