<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class User extends Model {}

final class Ticket extends Model
{
    protected $primaryKey = 'ticket_id';
}

namespace App\Data\Keys;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Validation\Rules\Exists;
use Spatie\LaravelData\Attributes\Validation\Exists as ExistsRule;
use Spatie\LaravelData\Data;
use StrictlyLaravel\Data\InputData;
use StrictlyLaravel\Data\KeyOf;
use StrictlyLaravel\Data\QueryData;
use StrictlyLaravel\Data\RouteKeyOf;

final class TicketData extends Data
{
    public int $reporter_id = 0;

    public function __construct(
        public int $ticket_id,
        public int $id,
        public string $title,
        public bool $paid,
        public int $project_id,
        #[KeyOf(User::class)]
        public ?int $assigneeId,
        #[RouteKeyOf(User::class)]
        public int $owner_id,
        /** @var list<int> */
        public array $watcherIds,
        /** @var list<int> */
        public array $label_ids,
        /** @var list<int> */
        #[KeyOf(User::class)]
        public array $subscriber_ids,
    ) {}

    public static function fromModel(Ticket $ticket): self
    {
        return new self(1, 1, '', false, 1, null, 1, [], [], []);
    }
}

final class StoreTicketData extends InputData
{
    public function __construct(
        /** @var list<int> */
        public array $ids,
        public ?int $parent,
        #[ExistsRule('users', 'id')]
        public ?int $reviewer,
        /** @var list<int> */
        public array $tags,
        /** @var list<int> */
        #[KeyOf(User::class)]
        public array $followers,
        public string $title,
        public ?int $assigneeId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'ids.*' => ['exists:tickets,ticket_id'],
            'parent' => [self::ticketExists()],
            'tags.*' => 'required|exists:tags,id',
            'followers.*' => ['exists:users,id'],
            'title' => ['max:80'],
            'assigneeId' => ['exists:users,id'],
            'tasks.*.ownerId' => ['exists:users,id'],
        ];
    }

    private static function ticketExists(): Exists
    {
        return new Exists;
    }
}

final class TicketFiltersData extends QueryData
{
    public function __construct(
        public ?int $assigneeId = null,
        #[KeyOf(Ticket::class)]
        public ?int $parentId = null,
    ) {}
}

final class TicketFormatter
{
    public function __construct(
        public int $userId,
    ) {}
}
