<?php

namespace StrictlyLaravel\TypeScript;

use LogicException;
use Spatie\TypeScriptTransformer\References\CustomReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TransformedProviders\ActionAwareTransformedProvider;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProviderActions;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptRaw;
use StrictlyLaravel\Inertia\Flash;
use StrictlyLaravel\Inertia\Shared;

/**
 * Writes the app's shared props and flash Data classes into Inertia's global config typing (Problems P-012, P-013,
 * P-047), so `usePage().props`, `usePage().flash` and the `flash` event are typed everywhere with no hand-written
 * global type:
 *
 *     declare module '@inertiajs/core' {
 *         export interface InertiaConfig {
 *             sharedPageProps: SharedProps;
 *             flashDataType: Partial<FlashData>;
 *         }
 *     }
 *
 * Every key of the flash data is optional: a response carries only the keys flashed for it, and none on most pages.
 * An app without a subclass of Shared or Flash keeps Inertia's default type for it.
 */
final class InertiaConfigProvider implements ActionAwareTransformedProvider, TransformedProvider
{
    private TransformedProviderActions $actions;

    /**
     * @param  list<string>  $directories  where the app's Data classes live (app/)
     */
    public function __construct(private readonly array $directories) {}

    public function setActions(TransformedProviderActions $actions): void
    {
        $this->actions = $actions;
    }

    /**
     * @return list<Transformed>
     */
    public function provide(): array
    {
        $classes = $this->concreteClasses();
        $shared = self::onlySubclassOf($classes, Shared::class);
        $flash = self::onlySubclassOf($classes, Flash::class);
        $lines = [];
        $references = [];

        if ($shared !== null) {
            $lines[] = '        sharedPageProps: %SharedProps%;';
            $references['SharedProps'] = $shared;
        }

        if ($flash !== null) {
            $lines[] = '        flashDataType: Partial<%FlashData%>;';
            $references['FlashData'] = $flash;
        }

        if ($lines === []) {
            return [];
        }

        return [new Transformed(
            new TypeScriptRaw(implode("\n", [
                '// The shared props and flash data every page receives, from their Data classes.',
                "declare module '@inertiajs/core' {",
                '    export interface InertiaConfig {',
                ...$lines,
                '    }',
                '}',
            ]), references: $references),
            new CustomReference('strictly-inertia-config', 'config'),
            [],
            false,
        )];
    }

    /**
     * @return list<string>
     */
    private function concreteClasses(): array
    {
        $classes = [];

        foreach ($this->actions->discoverTypesAction->execute($this->directories) as $node) {
            if (! $node->isAbstract()) {
                $classes[] = $node->getName();
            }
        }

        sort($classes);

        return $classes;
    }

    /**
     * The one class in $classes that extends $base, if there is one.
     *
     * @param  list<string>  $classes
     * @param  class-string  $base
     * @return class-string|null
     */
    private static function onlySubclassOf(array $classes, string $base): ?string
    {
        $found = array_values(array_filter($classes, fn (string $class): bool => is_subclass_of($class, $base)));

        if (count($found) > 1) {
            throw new LogicException("Only one class may extend {$base}, found ".implode(', ', $found).'.');
        }

        return $found[0] ?? null;
    }
}
