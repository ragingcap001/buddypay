<?php

namespace App\Domain\Users\Enums;

enum UserStatus: string
{
    case Active = 'ACTIVE';
    case Suspended = 'SUSPENDED';
    case Closed = 'CLOSED';
}
