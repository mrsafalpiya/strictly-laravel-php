<?php

namespace App\Data\Queries;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;
use StrictlyLaravel\Data\QueryData;

enum IssueSort: string
{
    case Newest = 'newest';
}

final class IssueFiltersData extends QueryData
{
    public int $perPage = 25;

    public ?string $label;

    /**
     * @param  list<string>  $labels
     * @param  list<string>  $owners
     */
    public function __construct(
        public IssueSort $sort,
        public ?string $search = null,
        public ?string $assignee = 'me',
        public array $labels = [],
        public array $owners = ['me'],
        public IssueSort $order = IssueSort::Newest,
        public int|Optional $page = new Optional,
        string $unpromoted = '',
    ) {}
}

final class IssueData extends Data
{
    public function __construct(
        public IssueSort $sort,
    ) {}
}
