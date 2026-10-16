<?php

namespace App\Enums;

enum IssueStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}

namespace App\Data\Shapes;

use App\Enums\IssueStatus;
use Spatie\LaravelData\Attributes\Hidden;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Lazy;
use Spatie\LaravelData\Optional;
use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\Data\QueryData;
use StrictlyLaravel\Inertia\Page;

final class IssueData extends Data
{
    public function __construct(public int $number) {}
}

final class MilestoneData extends Data
{
    public function __construct(public string $title) {}
}

final class ProjectsPage extends Page
{
    /** @var array{open: int, closed: int} */
    public array $counts = ['open' => 0, 'closed' => 0];

    /**
     * @param  list<array{lead: ?string}>  $projects
     * @param  list<int|string>  $estimates
     * @param  list<IssueData|MilestoneData>  $focus
     * @param  list<list<IssueData>>  $columns
     * @param  array<string, array<int, IssueData>>  $issuesByStatus
     * @param  list<?string>  $leads
     * @param  ?array{name: string}  $owner
     * @param  array<string, list<array{lead: ?string}>>  $grouped
     * @param  DataCollection<int, IssueData|MilestoneData>  $pinned
     * @param  Optional|list<array{id: int}>  $recent
     */
    public function __construct(
        public array $projects,
        public array $estimates,
        public array $focus,
        public array $columns,
        public array $issuesByStatus,
        public array $leads,
        public ?array $owner,
        public array $grouped,
        public DataCollection $pinned,
        public Optional|array $recent,
        /** @var list<array{id: int}> */
        public array $archived,
    ) {}
}

final class StoreIssueData extends InputData
{
    /**
     * @param  list<int|string>  $estimates
     */
    public function __construct(
        public array $estimates,
    ) {}
}

final class IssueFiltersData extends QueryData
{
    /**
     * @param  list<array{field: string}>  $sort
     */
    public function __construct(
        public array $sort = [],
    ) {}
}

/**
 * @template TItem of IssueData|MilestoneData
 */
final class AllowedShapesData extends Data
{
    /**
     * @param  array<string, string>  $labels
     * @param  list<int>  $ids
     * @param  list<IssueData>  $issues
     * @param  list<IssueStatus>  $statuses
     * @param  list<'low'|'high'>  $priorities
     * @param  list<int|float>  $points
     * @param  array<value-of<IssueStatus>, int>  $counts
     * @param  Optional|list<int>  $maybe
     * @param  Lazy|array<string, int>  $deferred
     * @param  DataCollection<int, IssueData>  $collection
     * @param  list<TItem>  $items
     * @param  list<mixed>  $history
     * @param  array<string, mixed>  $meta
     * @param  array{internal: true}  $secret
     */
    public function __construct(
        public IssueData|MilestoneData $subject,
        public int|string $estimate,
        public ?string $lead,
        public array $labels,
        public array $ids,
        public array $issues,
        public array $statuses,
        public array $priorities,
        public array $points,
        public array $counts,
        public Optional|array $maybe,
        public Lazy|array $deferred,
        public DataCollection $collection,
        public array $items,
        public array $history,
        public array $meta,
        #[Hidden]
        public array $secret,
    ) {}

    /** @var array{cache: int} */
    private array $cache = ['cache' => 0];

    /** @var list<array{key: string}> */
    public static array $registry = [];
}

final class Formatter
{
    /** @var list<array{lead: ?string}> */
    public array $rows = [];
}

/**
 * @template TItem
 */
final class PageOfData extends Data
{
    /**
     * @param  list<TItem>  $items
     */
    public function __construct(public array $items) {}
}

final class ReportPage extends Page
{
    /**
     * @param  PageOfData<array{id: int}>  $rows
     * @param  PageOfData<IssueData>  $issues
     */
    public function __construct(
        public PageOfData $rows,
        public PageOfData $issues,
    ) {}
}

/**
 * @template TSubject of Data
 */
final class PinData extends Data
{
    /**
     * @param  TSubject  $subject
     */
    public function __construct(public Data $subject) {}
}

/**
 * @template TItem
 */
final class BoardData extends Data
{
    /**
     * @param  list<PageOfData<TItem>>  $columns
     */
    public function __construct(public array $columns) {}
}

final class FocusPage extends Page
{
    /**
     * @param  PageOfData<IssueData|MilestoneData>  $focus
     * @param  BoardData<IssueData|MilestoneData>  $board
     * @param  PinData<IssueData|MilestoneData>  $pin
     * @param  PageOfData<int|string>  $estimates
     * @param  PageOfData<'low'|'high'>  $priorities
     * @param  PageOfData<list<IssueData>>  $columns
     */
    public function __construct(
        public PageOfData $focus,
        public BoardData $board,
        public PinData $pin,
        public PageOfData $estimates,
        public PageOfData $priorities,
        public PageOfData $columns,
    ) {}
}

/**
 * @template TItem of Data
 */
final class TreeData extends Data
{
    /**
     * @param  list<TItem>  $items
     * @param  list<TreeData<TItem>>  $children
     */
    public function __construct(public array $items, public array $children) {}
}

final class OutlinePage extends Page
{
    /**
     * @param  TreeData<IssueData>  $outline
     */
    public function __construct(public TreeData $outline) {}
}
