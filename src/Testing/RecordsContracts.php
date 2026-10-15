<?php

namespace StrictlyLaravel\Testing;

use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * Records every request a feature test makes as Contracts (ContractRecorder, Problem P-065), and checks every route
 * key it sends (RouteKeyChecks, Problem P-116). The installer applies it to the app's feature tests in tests/Pest.php:
 *
 *     pest()->use(RecordsContracts::class)->in('Feature');
 *
 * Laravel's test case calls setUpRecordsContracts() once the application is booted, as it does for every trait, and
 * after it has migrated the database (RefreshDatabase), so each table can be given its own id range.
 */
trait RecordsContracts
{
    protected function setUpRecordsContracts(): void
    {
        ContractRecorder::listen(method_exists($this, 'getPrintableTestCaseMethodName')
            ? static::getPrintableTestCaseName().' > '.$this->getPrintableTestCaseMethodName()   // Pest
            : static::class.'::'.$this->name());

        RouteKeyChecks::listen($this->app);

        $uses = class_uses_recursive($this);

        if ((isset($uses[RefreshDatabase::class]) && ! isset($uses[LazilyRefreshDatabase::class])) || isset($uses[DatabaseMigrations::class])) {
            RouteKeyChecks::useDisjointIds();
        }
    }
}
