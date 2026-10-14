<?php

namespace App\Data;

use Laravel\Passkeys\Passkey;
use Spatie\LaravelData\Data;

final class PasskeyData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $authenticator,
        public string $created_at_diff,
        public ?string $last_used_at_diff,
    ) {}

    public static function fromPasskey(Passkey $passkey): self
    {
        return new self(
            id: $passkey->id,
            name: $passkey->name,
            authenticator: $passkey->authenticator,
            // Nullable only because timestamps() columns are; Eloquent always sets it.
            created_at_diff: $passkey->created_at?->diffForHumans() ?? '',
            last_used_at_diff: $passkey->last_used_at?->diffForHumans(),
        );
    }
}
