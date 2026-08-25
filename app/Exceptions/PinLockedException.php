<?php

namespace App\Exceptions;

class PinLockedException extends FinancialException
{
    public function __construct()
    {
        parent::__construct(
            'PIN_LOCKED',
            'Too many incorrect PIN attempts. Please try again later.',
            429,
        );
    }
}
