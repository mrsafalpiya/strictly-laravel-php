<?php

namespace StrictlyLaravel\TypeScript;

use Spatie\TypeScriptTransformer\References\CustomReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptRaw;

/**
 * Types `import.meta.env` from the `VITE_*` variables the app's .env.example lists (Problem P-058). Vite's own
 * `ImportMetaEnv` has an index signature, so `import.meta.env.VITE_APP_NAM` compiles and is `undefined` at runtime;
 * Vite's `strictImportMetaEnv` type option removes it, and each listed variable is declared a string:
 *
 *     declare global {
 *         interface ViteTypeOptions {
 *             strictImportMetaEnv: unknown;
 *         }
 *
 *         interface ImportMetaEnv {
 *             readonly VITE_APP_NAME: string;
 *         }
 *     }
 *
 * A variable .env.example doesn't list then fails `tsc` where it is read. The npm package's Vite plugin checks the
 * other direction: a listed variable the environment Vite builds with lacks (Problem P-140).
 */
final readonly class ViteEnvTypes implements TransformedProvider
{
    /** Vite exposes only variables with this prefix to the client (its `envPrefix` default). */
    public const string PREFIX = 'VITE_';

    public function __construct(private string $envExample) {}

    /**
     * The `VITE_*` variables an env file lists, in order; commented-out lines don't count.
     *
     * @return list<string>
     */
    public static function variables(string $envFile): array
    {
        $contents = is_file($envFile) ? file_get_contents($envFile) : false;

        if ($contents === false) {
            return [];
        }

        preg_match_all('/^[ \t]*(?:export[ \t]+)?('.self::PREFIX.'[A-Za-z0-9_]*)[ \t]*=/m', $contents, $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * @return list<Transformed>
     */
    public function provide(): array
    {
        $variables = array_map(
            fn (string $variable): string => "        readonly {$variable}: string;",
            self::variables($this->envExample),
        );

        return [new Transformed(
            new TypeScriptRaw(implode("\n", [
                '// import.meta.env: the VITE_* variables .env.example lists, and no others.',
                'declare global {',
                '    interface ViteTypeOptions {',
                '        strictImportMetaEnv: unknown;',
                '    }',
                '',
                '    interface ImportMetaEnv {',
                ...$variables,
                '    }',
                '}',
            ])),
            new CustomReference('strictly-vite-env', 'env'),
            [],
            false,
        )];
    }
}
