<?php

namespace StrictlyLaravel\Testing;

use Illuminate\Http\Request;

/**
 * The request of every feature test (RecordsContracts): a plain Laravel request that reports every input key the app
 * reads by name to InputReads (Problem P-024, TracksInputReads).
 */
final class TrackedRequest extends Request
{
    use TracksInputReads;
}
