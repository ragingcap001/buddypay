<?php

namespace App\Exceptions;

class CircuitOpenException extends FinancialException
{
    public function __construct(string $provider)
    {
        parent::__construct(
            'PROVIDER_UNAVAILABLE',
            "Provider [{$provider}] is currently unavailable (circuit open).",
            503,
        );
    }
}
