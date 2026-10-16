<?php

namespace StrictlyLaravel\Testing;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Records every request a feature test makes as Contracts (ContractRecorder, Problem P-065), and checks every route
 * key it sends (RouteKeyChecks, Problem P-116). The installer applies it to the app's feature tests in tests/Pest.php:
 *
 *     pest()->use(RecordsContracts::class)->in('Feature');
 *
 * Laravel's test case calls setUpRecordsContracts() once the application is booted, as it does for every trait, and
 * after it has migrated the database (RefreshDatabase), so each table can be given its own id range. Each request the
 * test makes is a TrackedRequest, which reports the input keys the app reads (Problem P-024). Feature tests render
 * pages without the Vite build (Problem P-124).
 */
trait RecordsContracts
{
    protected function setUpRecordsContracts(): void
    {
        // Problem P-124: a page's HTML links its assets through the Vite manifest, which only a build writes, so in a
        // fresh clone (public/build is gitignored) every page a test renders would fail. Contracts check payloads, not
        // assets; a test that needs the real tags calls $this->withVite().
        $this->withoutVite();

        ContractRecorder::listen($this->recordedTestName());

        RouteKeyChecks::listen($this->app);

        $uses = class_uses_recursive($this);

        if ((isset($uses[RefreshDatabase::class]) && ! isset($uses[LazilyRefreshDatabase::class])) || isset($uses[DatabaseMigrations::class])) {
            RouteKeyChecks::useDisjointIds();
        }
    }

    /** The test's name in the Contracts it records: Pest's file and description, or PHPUnit's class and method. */
    private function recordedTestName(): string
    {
        if (method_exists($this, 'getPrintableTestCaseName') && method_exists($this, 'getPrintableTestCaseMethodName')) {
            $file = $this::getPrintableTestCaseName();
            $description = $this->getPrintableTestCaseMethodName();

            if (is_string($file) && is_string($description)) {
                return "{$file} > {$description}";
            }
        }

        // @phpstan-ignore method.internal (PHPUnit has no public API for the running test's method name; Laravel's own TestCase reads it the same way)
        return static::class.'::'.$this->name();
    }

    /**
     * @param  SymfonyRequest  $symfonyRequest
     */
    protected function createTestRequest($symfonyRequest): Request
    {
        return TrackedRequest::createFromBase($symfonyRequest);
    }
}
