<?php

namespace StrictlyLaravel\Coverage;

use Spatie\LaravelData\Support\DataConfig;
use stdClass;
use StrictlyLaravel\Inertia\Page;

/**
 * Contract Coverage (Problem P-066): what the recorded Contracts never exercised. `tsc` proves a generated type only
 * for the payloads the feature tests produced, so a page no test renders, or a state its type permits that no
 * Contract showed (ShapeCoverage), was never checked at all.
 *
 * Each dimension reads the Contract records of its kind:
 *
 * - pages: every Page class must be rendered by some test (`page` records), and its props' shapes are observed.
 *
 * A Type Boundary that records Contracts of a new kind (deferred props, JSON responses, form endpoints, declared
 * vendor fields, broadcasts) adds its dimension here: an unexercised Gap for what no test reached, and its Data
 * values fed to the same ShapeCoverage so their shapes are proven too.
 */
final readonly class ContractCoverage
{
    public function __construct(
        private DataConfig $config,
    ) {}

    /**
     * Every gap, the unexercised ones first.
     *
     * @param  list<stdClass>  $records  the Contract sidecars (ContractRecorder::records())
     * @param  array<string, class-string<Page>>  $pages  page component => Page class (PageLocations::pages())
     * @return list<Gap>
     */
    public function gaps(array $records, array $pages): array
    {
        $shapes = new ShapeCoverage($this->config);
        $unexercised = $this->pages($records, $pages, $shapes);

        return [...$unexercised, ...$shapes->gaps()];
    }

    /**
     * @param  list<stdClass>  $records
     * @param  array<string, class-string<Page>>  $pages
     * @return list<Gap>
     */
    private function pages(array $records, array $pages, ShapeCoverage $shapes): array
    {
        $rendered = [];

        foreach ($records as $record) {
            if ($record->kind === 'page' && is_string($record->component ?? null)) {
                $rendered[$record->component][] = $record->props ?? null;
            }
        }

        $gaps = [];

        foreach ($pages as $component => $class) {
            if (! isset($rendered[$component])) {
                $gaps[] = new Gap(
                    "{$component}: no feature test renders this page",
                    [],
                    "add a feature test that requests a route returning {$class}",
                    unexercised: true,
                );

                continue;
            }

            $shapes->observe($class, $rendered[$component], Origin::page($component));
        }

        return $gaps;
    }
}
