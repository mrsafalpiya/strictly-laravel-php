<?php

namespace StrictlyLaravel\TypeScript;

use Spatie\TypeScriptTransformer\References\CustomReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptRaw;
use StrictlyLaravel\Inertia\GlobalDataClasses;

/**
 * Writes the app's shared props and flash Data classes (GlobalDataClasses) into Inertia's global config typing
 * (Problems P-012, P-047), so `usePage().props`, `usePage().flash` and the `flash` event are typed everywhere with no
 * hand-written global type:
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
final readonly class InertiaConfigProvider implements TransformedProvider
{
    /**
     * @param  list<string>  $directories  where the app's Data classes live (app/)
     */
    public function __construct(private array $directories) {}

    /**
     * @return list<Transformed>
     */
    public function provide(): array
    {
        $classes = GlobalDataClasses::in($this->directories);
        $lines = [];
        $references = [];

        if ($classes->shared !== null) {
            $lines[] = '        sharedPageProps: %SharedProps%;';
            $references['SharedProps'] = $classes->shared;
        }

        if ($classes->flash !== null) {
            $lines[] = '        flashDataType: Partial<%FlashData%>;';
            $references['FlashData'] = $classes->flash;
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
}
