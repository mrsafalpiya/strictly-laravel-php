<?php

namespace StrictlyLaravel\Testing;

/**
 * Records every request a feature test makes as Contracts (ContractRecorder, Problem P-065). The installer applies it
 * to the app's feature tests in tests/Pest.php:
 *
 *     pest()->use(RecordsContracts::class)->in('Feature');
 *
 * Laravel's test case calls setUpRecordsContracts() once the application is booted, as it does for every trait.
 */
trait RecordsContracts
{
    protected function setUpRecordsContracts(): void
    {
        ContractRecorder::listen(method_exists($this, 'getPrintableTestCaseMethodName')
            ? static::getPrintableTestCaseName().' > '.$this->getPrintableTestCaseMethodName()   // Pest
            : static::class.'::'.$this->name());
    }
}
