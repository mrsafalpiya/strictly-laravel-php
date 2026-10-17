<?php

namespace App\Enums;

enum LabelColor: string
{
    case Red = 'red';
    case Blue = 'blue';
}

namespace App\Data\Lists;

use App\Enums\LabelColor;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\Data\QueryData;
use StrictlyLaravel\Inertia\Page;

final class LabelData extends Data
{
    public function __construct(public string $name) {}
}

final class LabelsPage extends Page
{
    /** @var array<int, LabelData> */
    public array $declared = [];

    /**
     * @param  array<int, LabelData>  $intKeyed
     * @param  array<LabelData>  $unkeyed
     * @param  LabelData[]  $bracketed
     * @param  non-empty-array<int, LabelData>  $nonEmpty
     * @param  array<int<0, max>, LabelData>  $ranged
     * @param  array<int, LabelData>|null  $nullable
     * @param  ?array<int, LabelData>  $shortNullable
     * @param  list<LabelData>  $list
     * @param  non-empty-list<LabelData>  $nonEmptyList
     * @param  array<string, LabelData>  $byName
     * @param  array<array-key, LabelData>  $byKey
     * @param  array<value-of<LabelColor>, int>  $byColor
     * @param  array<int, LabelData>  $hidden
     */
    public function __construct(
        public array $intKeyed,
        public array $unkeyed,
        public array $bracketed,
        public array $nonEmpty,
        public array $ranged,
        public ?array $nullable,
        public ?array $shortNullable,
        public array $list,
        public array $nonEmptyList,
        public array $byName,
        public array $byKey,
        public array $byColor,
        /** @var array<int, LabelData> */
        public array $inline,
        #[Hidden]
        public array $hidden,
    ) {}
}

final class LabelUpdateData extends Data
{
    /**
     * @param  Optional|array<int, LabelData>  $labels
     */
    public function __construct(
        public Optional|array $labels,
    ) {}
}

final class StoreLabelsData extends InputData
{
    /**
     * @param  array<int, int>  $ids
     */
    public function __construct(public array $ids) {}
}

final class LabelFiltersData extends QueryData
{
    /**
     * @param  array<int, string>  $names
     */
    public function __construct(public array $names = []) {}
}
