<?php

namespace App\Enums;

enum ToastType: string
{
    case Success = 'success';
    case Info = 'info';
    case Warning = 'warning';
    case Error = 'error';
}
