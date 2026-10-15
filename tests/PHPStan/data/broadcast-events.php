<?php

namespace App\Data;

use Spatie\LaravelData\Data;
use StrictlyLaravel\Inertia\Page;

final class CommentPostedData extends Data
{
    public function __construct(public int $id) {}
}

final class IssueClosedData extends Data
{
    public function __construct(public int $id) {}
}

final class CommentsPage extends Page {}

namespace App\Events;

use App\Data\CommentPostedData;
use App\Data\CommentsPage;
use App\Data\IssueClosedData;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use StrictlyLaravel\Broadcasting\BroadcastsData;

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

final class CommentEdited implements ShouldBroadcast
{
    public function __construct(public CommentPostedData $comment) {}

    /** @return list<string> */
    public function broadcastOn(): array
    {
        return ['private-issues.1'];
    }
}

final class CommentDeleted implements ShouldBroadcastNow
{
    /** @return list<string> */
    public function broadcastOn(): array
    {
        return ['private-issues.1'];
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return ['id' => 1];
    }
}

final class IssueClosed implements ShouldBroadcast
{
    use BroadcastsData;

    /** @return list<string> */
    public function broadcastOn(): array
    {
        return ['private-issues.1'];
    }

    public function broadcastData(): IssueClosedData
    {
        return new IssueClosedData(1);
    }

    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return ['id' => 1];
    }
}

final class IssueReopened implements ShouldBroadcast
{
    use BroadcastsData;

    /** @return list<string> */
    public function broadcastOn(): array
    {
        return ['private-issues.1'];
    }

    public function broadcastData(): CommentPostedData|IssueClosedData
    {
        return new IssueClosedData(1);
    }
}

final class CommentsRefreshed implements ShouldBroadcast
{
    use BroadcastsData;

    /** @return list<string> */
    public function broadcastOn(): array
    {
        return ['private-issues.1'];
    }

    public function broadcastData(): CommentsPage
    {
        return new CommentsPage;
    }
}

abstract class IssueEvent implements ShouldBroadcast {}

// Not broadcast: no rule applies.
final class IssueViewed
{
    /** @return array<string, int> */
    public function broadcastWith(): array
    {
        return ['id' => 1];
    }
}
