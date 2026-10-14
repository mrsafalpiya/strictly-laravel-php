<?php

namespace App\Data;

use App\Enums\ToastType;
use Spatie\LaravelData\Data;

final class ToastData extends Data
{
    public function __construct(
        public ToastType $type,
        public string $message,
    ) {}
}
