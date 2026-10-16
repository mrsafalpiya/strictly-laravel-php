<?php

namespace StrictlyLaravel\Tests\Analysis;

use Illuminate\Foundation\Testing\TestCase;
use StrictlyLaravel\Testing\RecordsContracts;

/**
 * A feature test case as an app's tests/Pest.php sets one up, so `pnpm analyse:php` analyses RecordsContracts: PHPStan
 * analyses a trait only in a class that uses it, and nothing in the package does (Problem P-203).
 */
abstract class AnalysedTestCase extends TestCase
{
    use RecordsContracts;
}
