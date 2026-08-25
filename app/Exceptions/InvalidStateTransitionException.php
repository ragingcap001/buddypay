<?php

namespace App\Exceptions;

class InvalidStateTransitionException extends FinancialException
{
    public function __construct(string $from, string $to)
    {
        parent::__construct(
            'INVALID_STATE_TRANSITION',
            "Invalid transaction state transition: {$from} -> {$to}.",
            409,
        );
    }
}
