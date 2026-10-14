<?php

namespace StrictlyLaravel\Workarounds\TypeScriptTransformer;

use RuntimeException;

/** Problem P-061: a generated type would reference a PHP class that has no TypeScript type. Deleted with UnmappedClassCheck. */
final class UnmappedClassException extends RuntimeException {}
