<?php

namespace App\Data;

use Illuminate\Validation\Rules\Password;
use StrictlyLaravel\Data\InputData;

final class PasswordUpdateData extends InputData
{
    public function __construct(
        public string $current_password,
        public string $password,
        public string $password_confirmation,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'current_password' => ['current_password'],
            'password' => [Password::default(), 'confirmed'],
        ];
    }
}
