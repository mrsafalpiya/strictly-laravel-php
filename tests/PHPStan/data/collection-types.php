<?php

namespace App\Enums;

enum IssueStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
}

enum IssuePriority: int
{
    case Low = 1;
    case High = 2;
}

enum Size
{
    case Small;
    case Large;
}

namespace App\CollectionTypes;

use App\Enums\IssuePriority;
use App\Enums\IssueStatus;
use App\Enums\Size;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

use function PHPStan\Testing\assertType;

final class Label extends Model {}

final class Issue
{
    public function __construct(
        public string $title,
        public IssueStatus $status,
        public IssuePriority $priority,
        public Size $size,
    ) {}
}

/**
 * @param  Collection<int, Issue>  $issues
 * @param  Collection<int, IssueStatus>  $statuses
 * @param  Collection<string, Issue>  $byTitle
 */
function values(Collection $issues, Collection $statuses, Collection $byTitle): void
{
    // P-206: values() renumbers the keys, so all() on it is a list; all() on anything else keeps Laravel's type.
    assertType('list<App\CollectionTypes\Issue>', $issues->filter()->values()->all());
    assertType('list<App\CollectionTypes\Issue>', $byTitle->values()->all());
    assertType('list<App\Enums\IssueStatus>', $statuses->values()->all());
    assertType('array<int, App\CollectionTypes\Issue>', $issues->filter()->all());
    assertType('array<string, App\CollectionTypes\Issue>', $byTitle->all());
}

/**
 * @param  Builder<Label>  $query
 * @param  list<Issue>  $list
 * @param  array<int, Issue>  $intKeyed
 */
function provenLists(Builder $query, array $list, array $intKeyed): void
{
    // A chain that starts from a list and keeps each key ends in a list.
    assertType('list<App\CollectionTypes\Label>', $query->get()->all());
    assertType('list<string>', $query->get()->map(fn (Label $label): string => $label::class)->all());
    assertType('list<App\CollectionTypes\Label>', Label::all()->all());
    assertType('list<App\CollectionTypes\Issue>', collect($list)->each(fn (Issue $issue) => null)->all());
    assertType('list<string>', $query->get()->filter()->values()->map(fn (Label $label): string => $label::class)->all());

    // A call that can leave a gap or reorder the keys, or a source that isn't a list, keeps Laravel's type.
    assertType('array<int, App\CollectionTypes\Label>', $query->get()->filter()->all());
    assertType('array<int, App\CollectionTypes\Label>', $query->get()->sortBy('id')->all());
    assertType('array<int, string>', $query->get()->filter()->map(fn (Label $label): string => $label::class)->all());
    assertType('array<int, App\CollectionTypes\Issue>', collect($intKeyed)->all());
}

/**
 * @param  Collection<int, Issue>  $issues
 * @param  Collection<int, IssueStatus>  $statuses
 * @param  Collection<int, string>  $titles
 */
function countBy(Collection $issues, Collection $statuses, Collection $titles): void
{
    // P-207: the counts are keyed by what the callback returns, an enum case by its value (its name for a pure enum).
    assertType("Illuminate\Support\Collection<'closed'|'open', int<1, max>>", $issues->countBy(fn (Issue $issue) => $issue->status));
    assertType('array<1|2, int<1, max>>', $issues->countBy(fn (Issue $issue) => $issue->priority)->all());
    assertType("array<'Large'|'Small', int<1, max>>", $issues->countBy(fn (Issue $issue) => $issue->size)->all());
    assertType("array<'closed'|'open', int<1, max>>", $issues->countBy(fn (Issue $issue): IssueStatus => $issue->status)->all());
    assertType('array<string, int<1, max>>', $issues->countBy(fn (Issue $issue): string => $issue->title)->all());
    assertType("array<'closed'|'open', int<1, max>>", $statuses->countBy()->all());
    assertType('array<string, int<1, max>>', $titles->countBy()->all());

    // A field name, or keys that aren't ints, strings or enums, keep Laravel's own type.
    assertType('Illuminate\Support\Collection<(int|string), int>', $issues->countBy('status'));
    assertType('Illuminate\Support\Collection<(int|string), int>', $issues->countBy());
}
