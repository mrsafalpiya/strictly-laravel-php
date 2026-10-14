<?php

namespace App\Data;

use StrictlyLaravel\Data\InputData;

final class ProfileDeleteData extends InputData
{
    public function __construct(
        public string $password,
    ) {}

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(): array
    {
        return [
            'password' => ['current_password'],
        ];
    }
}
