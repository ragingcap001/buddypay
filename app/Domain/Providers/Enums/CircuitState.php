<?php

namespace App\Domain\Providers\Enums;

enum CircuitState: string
{
    case Closed = 'CLOSED';
    case Open = 'OPEN';
    case HalfOpen = 'HALF_OPEN';
}
