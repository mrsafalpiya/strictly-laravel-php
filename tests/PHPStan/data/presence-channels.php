<?php

namespace App\Data;

use Spatie\LaravelData\Data;

final class ViewerData extends Data
{
    public function __construct(public int $id, public string $name) {}
}

namespace App\Broadcasting;

use App\Data\ViewerData;

final class ProjectWatchersChannel
{
    /**
     * @return array{id: int}
     */
    public function join(object $user, int $project): array
    {
        return ['id' => $project];
    }
}

final class TeamViewersChannel
{
    public function join(object $user, int $team): ?ViewerData
    {
        return new ViewerData($team, 'Ada');
    }
}

namespace App\Routes;

use App\Broadcasting\ProjectWatchersChannel;
use App\Broadcasting\TeamViewersChannel;
use App\Data\ViewerData;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Support\Facades\Broadcast;

function channels(Broadcaster $broadcaster, string $pattern): void
{
    // Private channels, and presence channels typed by a Data class, are fine.
    Broadcast::channel('projects.{project}', fn (object $user, int $project): bool => $project > 0);
    Broadcast::channel('teams.{team}.members', fn (object $user, int $team): ?ViewerData => $team > 0 ? new ViewerData($team, 'Ada') : null);
    Broadcast::channel('teams.{team}.viewers', TeamViewersChannel::class);

    Broadcast::channel('projects.{project}.viewers', fn (object $user, int $project): array => ['id' => $project]);
    Broadcast::channel('issues.{issue}.viewers', function (object $user, int $issue): array|false {
        return $issue > 0 ? ['id' => $issue] : false;
    });
    Broadcast::channel('issues.{issue}.watchers', fn (object $user, int $issue) => ['id' => $issue]);
    Broadcast::channel('projects.{project}.watchers', ProjectWatchersChannel::class);
    $broadcaster->channel('workspaces.{workspace}.viewers', fn (object $user, int $workspace): array => ['id' => $workspace]);
    Broadcast::channel($pattern, fn (object $user): array => ['id' => 1]);

    // A Data class the callback doesn't declare: generation reads only the declared return type.
    Broadcast::channel('boards.{board}.viewers', fn (object $user, int $board) => new ViewerData($board, 'Ada'));
}
