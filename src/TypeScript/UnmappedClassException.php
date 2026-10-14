<?php

namespace StrictlyLaravel\TypeScript;

use RuntimeException;

/** Problem P-061: a generated type would reference a PHP class that has no TypeScript type. See UnmappedClassCheck. */
final class UnmappedClassException extends RuntimeException {}
