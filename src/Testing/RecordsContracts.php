<?php

namespace StrictlyLaravel\Testing;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Records every request a feature test makes as Contracts (ContractRecorder, Problem P-065). The installer applies it
 * to the app's feature tests in tests/Pest.php:
 *
 *     pest()->use(RecordsContracts::class)->in('Feature');
 *
 * Laravel's test case calls setUpRecordsContracts() once the application is booted, as it does for every trait. Each
 * request the test makes is a TrackedRequest, which reports the input keys the app reads (Problem P-024).
 */
trait RecordsContracts
{
    protected function setUpRecordsContracts(): void
    {
        ContractRecorder::listen(method_exists($this, 'getPrintableTestCaseMethodName')
            ? static::getPrintableTestCaseName().' > '.$this->getPrintableTestCaseMethodName()   // Pest
            : static::class.'::'.$this->name());
    }

    /**
     * @param  SymfonyRequest  $symfonyRequest
     */
    protected function createTestRequest($symfonyRequest): Request
    {
        return TrackedRequest::createFromBase($symfonyRequest);
    }
}
