<?php

namespace App\Data;

use App\Models\User;
use Carbon\CarbonInterface;
use Spatie\LaravelData\Data;

/**
 * The signed-in user as every page sees it: only these fields reach the browser, never the whole model.
 */
final class UserData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public ?CarbonInterface $email_verified_at,
    ) {}

    public static function fromModel(User $user): self
    {
        return new self(
            id: $user->id,
            name: $user->name,
            email: $user->email,
            email_verified_at: $user->email_verified_at,
        );
    }
}
