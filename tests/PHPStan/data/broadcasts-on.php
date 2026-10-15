<?php

namespace App\Data;

use Spatie\LaravelData\Data;

final class CommentPostedData extends Data
{
    public function __construct(public int $id) {}
}

namespace App\Events;

use App\Data\CommentPostedData;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use StrictlyLaravel\Broadcasting\BroadcastsData;
use StrictlyLaravel\Broadcasting\BroadcastsOn;

#[BroadcastsOn('issues.{issue}')]
final class CommentPosted implements ShouldBroadcast
{
    use BroadcastsData;

    /** @return list<string> */
    public function broadcastOn(): array
    {
        return ['private-issues.1'];
    }

    public function broadcastData(): CommentPostedData
    {
        return new CommentPostedData(1);
    }
}

#[BroadcastsOn('issues.{issue}')]
#[BroadcastsOn('projects.{project}')]
final class CommentEdited implements ShouldBroadcastNow
{
    use BroadcastsData;

    /** @return list<string> */
    public function broadcastOn(): array
    {
        return ['private-issues.1', 'private-projects.1'];
    }

    public function broadcastData(): CommentPostedData
    {
        return new CommentPostedData(1);
    }
}

final class CommentDeleted implements ShouldBroadcast
{
    use BroadcastsData;

    /** @return list<string> */
    public function broadcastOn(): array
    {
        return ['private-issues.1'];
    }

    public function broadcastData(): CommentPostedData
    {
        return new CommentPostedData(1);
    }
}

final class CommentHidden implements ShouldBroadcastNow
{
    use BroadcastsData;

    /** @return list<string> */
    public function broadcastOn(): array
    {
        return ['private-issues.1'];
    }

    public function broadcastData(): CommentPostedData
    {
        return new CommentPostedData(1);
    }
}

// Abstract: its subclasses declare their own channels.
abstract class IssueEvent implements ShouldBroadcast {}

// Not broadcast: no rule applies.
final class IssueViewed {}
