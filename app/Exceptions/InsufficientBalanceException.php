<?php

namespace App\Exceptions;

class InsufficientBalanceException extends FinancialException
{
    public function __construct(int $available, int $required)
    {
        parent::__construct(
            'INSUFFICIENT_BALANCE',
            "Insufficient wallet balance: available {$available}, required {$required}.",
            422,
        );
    }
}
