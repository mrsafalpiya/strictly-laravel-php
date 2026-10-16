<?php

namespace StrictlyLaravel\Workarounds\TypeScriptTransformer;

use Spatie\TypeScriptTransformer\Collections\TransformedCollection;
use Spatie\TypeScriptTransformer\Data\GlobalNamespaceResolvedReference;
use Spatie\TypeScriptTransformer\Data\ModuleImportResolvedReference;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\References\CustomReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\Writers\Writer;

/**
 * Problem P-063 (docs/problems.md). Upstream: no upstream issue filed (typescript-transformer 3.3: `FlatModuleWriter`
 * writes every type into one module under its short name, without checking that the names are unique).
 *
 * `App\Data\Issues\SummaryData` and `App\Data\Projects\SummaryData` would both be written as `export type SummaryData`
 * into `@/generated`, and `tsc` would then fail on the generated file with a duplicate identifier that names neither PHP
 * class. Wrapped around the writer, this fails generation before anything is written instead, naming every class that
 * generates each clashing name, and the previous output stays. Delete this class and DuplicateTypeNamesException once
 * typescript-transformer reports such names itself.
 */
final readonly class DuplicateTypeNames implements Writer
{
    public function __construct(private Writer $writer) {}

    public function output(array $transformed, TransformedCollection $transformedCollection): array
    {
        $sources = [];

        foreach ($transformed as $item) {
            $name = $item->getName();

            if ($name !== null) {
                $sources[$name][] = self::source($item);
            }
        }

        $clashes = [];

        foreach ($sources as $name => $from) {
            if (count($from) > 1) {
                sort($from);
                $clashes[] = self::clash($name, $from);
            }
        }

        if ($clashes !== []) {
            sort($clashes);

            throw new DuplicateTypeNamesException($clashes);
        }

        return $this->writer->output($transformed, $transformedCollection);
    }

    public function resolveReference(Transformed $transformed): ModuleImportResolvedReference|GlobalNamespaceResolvedReference
    {
        return $this->writer->resolveReference($transformed);
    }

    /** What generated a type: its PHP class, or one of Strictly Laravel's own types (an app class named like one clashes). */
    private static function source(Transformed $transformed): string
    {
        $reference = $transformed->getReference();

        return match (true) {
            $reference instanceof ClassStringReference => "`{$reference->classString}`",
            $reference instanceof CustomReference => "Strictly Laravel's own type",
            default => $reference->humanFriendlyName(),
        };
    }

    /**
     * @param  list<string>  $sources  at least two
     */
    private static function clash(string $name, array $sources): string
    {
        $last = array_pop($sources);
        $both = count($sources) === 1 ? 'both' : 'all';

        return implode(', ', $sources)." and {$last} {$both} generate `{$name}`. Rename one, or give one a TypeScript name "
            ."with `#[TypeScript(name: '…')]`.";
    }
}
