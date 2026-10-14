<?php

namespace StrictlyLaravel\TypeScript;

use RuntimeException;

/** Problem P-043: a generated type would hold a raw Inertia DeferProp or OptionalProp (RawInertiaPropCheck). */
final class RawInertiaPropException extends RuntimeException {}
