<?php

namespace StrictlyLaravel\Workarounds\TypeScriptTransformer;

use RuntimeException;

/** Problem P-063: two or more PHP classes would generate the same type name in `@/generated`. Deleted with DuplicateTypeNames. */
final class DuplicateTypeNamesException extends RuntimeException
{
    /**
     * @param  list<string>  $clashes  one sentence per clashing name, naming every class that generates it and the fix
     */
    public function __construct(public readonly array $clashes)
    {
        parent::__construct(implode("\n", $clashes));
    }
}
