<?php

namespace StrictlyLaravel\Coverage;

use Spatie\LaravelData\Support\DataConfig;
use stdClass;
use StrictlyLaravel\Inertia\GlobalDataClasses;
use StrictlyLaravel\Inertia\Page;

/**
 * Contract Coverage (Problem P-066): what the recorded Contracts never exercised. `tsc` proves a generated type only
 * for the payloads the feature tests produced, so a page no test renders, or a state its type permits that no
 * Contract showed (ShapeCoverage), was never checked at all.
 *
 * Each dimension reads the Contract records of its kind:
 *
 * - pages: every Page class must be rendered by some test (`page` records), and its props' shapes are observed.
 * - shared props: some test must render a page with them (`page` records' `shared`), and their shapes are observed
 *   against the shared props Data class.
 * - flash: what was flashed, with a page (`page` records' `flash`) or before a redirect (`flash` records), is observed
 *   against the flash Data class, so a key no test flashes is a gap.
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
     * @param  GlobalDataClasses|null  $global  the shared props and flash Data classes (GlobalDataClasses::in())
     * @return list<Gap>
     */
    public function gaps(array $records, array $pages, ?GlobalDataClasses $global = null): array
    {
        $shapes = new ShapeCoverage($this->config);
        $unexercised = [
            ...$this->pages($records, $pages, $shapes),
            ...$this->shared($records, $global?->shared, $shapes),
            ...$this->flash($records, $global?->flash, $shapes),
        ];

        return [...$unexercised, ...$shapes->gaps()];
    }

    /**
     * @param  list<stdClass>  $records
     * @param  class-string|null  $class
     * @return list<Gap>
     */
    private function shared(array $records, ?string $class, ShapeCoverage $shapes): array
    {
        if ($class === null) {
            return [];
        }

        $values = [];

        foreach ($records as $record) {
            if ($record->kind === 'page' && ($record->shared ?? null) instanceof stdClass) {
                $values[] = $record->shared;
            }
        }

        if ($values === []) {
            return [new Gap(
                'shared props: no feature test renders a page through the Inertia middleware',
                [],
                'add a feature test that requests a page route in the web middleware group',
                unexercised: true,
            )];
        }

        $shapes->observe($class, $values, new Origin('shared props', 'renders a page'));

        return [];
    }

    /**
     * @param  list<stdClass>  $records
     * @param  class-string|null  $class
     * @return list<Gap>
     */
    private function flash(array $records, ?string $class, ShapeCoverage $shapes): array
    {
        if ($class === null) {
            return [];
        }

        $values = [];

        foreach ($records as $record) {
            if (in_array($record->kind, ['page', 'flash'], true) && ($record->flash ?? null) instanceof stdClass) {
                $values[] = $record->flash;
            }
        }

        // An empty object stands for every response that flashed nothing, so each key the class declares is
        // reported as never sent until some test flashes it.
        $shapes->observe($class, [...$values, new stdClass], new Origin('flash data', 'flashes data'));

        return [];
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
