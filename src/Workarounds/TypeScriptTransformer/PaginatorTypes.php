<?php

namespace StrictlyLaravel\Workarounds\TypeScriptTransformer;

use Illuminate\Pagination\CursorPaginator;
use Illuminate\Pagination\LengthAwarePaginator;
use Spatie\TypeScriptTransformer\References\ClassStringReference;
use Spatie\TypeScriptTransformer\Transformed\Transformed;
use Spatie\TypeScriptTransformer\TransformedProviders\TransformedProvider;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptAlias;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptGeneric;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptIdentifier;
use Spatie\TypeScriptTransformer\TypeScriptNodes\TypeScriptRaw;

/**
 * Problem P-040 (docs/problems.md). Upstream: no upstream issue filed (laravel-typescript-transformer 3.3);
 * https://github.com/spatie/typescript-transformer/issues/116 reported a different cursor paginator mismatch against v2.
 *
 * Replaces the paginator types laravel-typescript-transformer ships, which `PaginatedDataCollection` and
 * `CursorPaginatedDataCollection` alias:
 *
 * - `data` is always a JSON list: the shipped type makes it a `Record` when the key type is a string, and
 *   laravel-data's `collect()` keys cursor paginators by `array-key`;
 * - a page paginator's links carry the `page` number Laravel sends since Laravel 11, which the shipped type omits.
 *
 * Registered after the laravel-data extension, so it replaces the same references. Delete this class once upstream
 * ships these shapes.
 */
final class PaginatorTypes implements TransformedProvider
{
    public function provide(): array
    {
        return [
            $this->paginator(LengthAwarePaginator::class, 'LengthAwarePaginator', <<<'TS'
                {
                    data: TValue[];
                    links: { url: string | null; label: string; page: number | null; active: boolean }[];
                    meta: {
                        current_page: number;
                        first_page_url: string;
                        from: number | null;
                        last_page: number;
                        last_page_url: string;
                        next_page_url: string | null;
                        path: string;
                        per_page: number;
                        prev_page_url: string | null;
                        to: number | null;
                        total: number;
                    };
                }
                TS),
            $this->paginator(CursorPaginator::class, 'CursorPaginator', <<<'TS'
                {
                    data: TValue[];
                    links: { url: string | null; label: string; active: boolean }[];
                    meta: {
                        path: string;
                        per_page: number;
                        next_cursor: string | null;
                        next_page_url: string | null;
                        prev_cursor: string | null;
                        prev_page_url: string | null;
                    };
                }
                TS),
        ];
    }

    /**
     * @param  class-string  $class
     */
    private function paginator(string $class, string $name, string $typeScript): Transformed
    {
        return new Transformed(
            new TypeScriptAlias(
                new TypeScriptGeneric(new TypeScriptIdentifier($name), [new TypeScriptIdentifier('TKey'), new TypeScriptIdentifier('TValue')]),
                new TypeScriptRaw($typeScript),
            ),
            new ClassStringReference($class),
            ['Illuminate'],
            true,
        );
    }
}
