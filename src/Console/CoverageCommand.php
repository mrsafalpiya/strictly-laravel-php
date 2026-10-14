<?php

namespace StrictlyLaravel\Console;

use Illuminate\Console\Command;
use Spatie\LaravelData\Support\DataConfig;
use StrictlyLaravel\Coverage\ContractCoverage;
use StrictlyLaravel\Coverage\Gap;
use StrictlyLaravel\Inertia\GlobalDataClasses;
use StrictlyLaravel\Inertia\PageLocations;
use StrictlyLaravel\Routes\FormEndpoints;
use StrictlyLaravel\Testing\ContractRecorder;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Reports what the Contracts the feature tests recorded never exercised (Contract Coverage, Problem P-066), each gap
 * with the test that closes it. A page no test renders always fails; a shape its type permits that no Contract
 * showed fails with `--strict`, which is how `ci:check` runs it (`ci:check:coverage`, after the tests).
 *
 * Each gap is one line, so it isn't wrapped to the terminal's width:
 *
 *     ✗ ProjectData.lead is only ever null (at projects/show › project.lead): add a feature test that renders …
 */
#[AsCommand(name: 'strictly:coverage')]
final class CoverageCommand extends Command
{
    protected $signature = 'strictly:coverage
        {--strict : Also fail on shapes no Contract showed (a nullable field only ever null, a list only ever empty, …)}';

    protected $description = 'Report the pages and shapes the Contracts recorded by the feature tests never exercised';

    public function handle(): int
    {
        // Pure analysis of files on disk: don't touch the app's cache store, which laravel-data may cache class
        // structures in and which a fresh CI checkout may have no database for.
        config(['data.structure_caching.cache.store' => 'array']);

        $records = ContractRecorder::records();
        $pages = PageLocations::pages();
        $formEndpoints = FormEndpoints::all(app('router'));

        if ($records === [] && ($pages !== [] || $formEndpoints !== [])) {
            $this->line('Contract Coverage: no Contracts are recorded. Run the feature tests first (composer ci:check:test), then this command.');

            return self::FAILURE;
        }

        $strict = $this->option('strict') === true;
        $gaps = (new ContractCoverage(app(DataConfig::class)))->gaps($records, $pages, GlobalDataClasses::in([app_path()]), $formEndpoints);
        $failing = array_filter($gaps, fn (Gap $gap): bool => $strict || $gap->unexercised);

        if ($gaps === []) {
            $this->line(sprintf('Contract Coverage: no gaps in the %d recorded Contracts.', count($records)));

            return self::SUCCESS;
        }

        $this->line(sprintf(
            'Contract Coverage: %d %s in the %d recorded Contracts (✗ fails%s):',
            count($gaps), count($gaps) === 1 ? 'gap' : 'gaps', count($records), $strict ? '' : '; ! fails with --strict',
        ));
        $this->newLine();

        foreach ($gaps as $gap) {
            $this->line(sprintf('  %s %s', $strict || $gap->unexercised ? '✗' : '!', OutputFormatter::escape($gap->describe())));
        }

        return $failing === [] ? self::SUCCESS : self::FAILURE;
    }
}
