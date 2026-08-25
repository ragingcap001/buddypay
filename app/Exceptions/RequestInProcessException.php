<?php

namespace App\Exceptions;

class RequestInProcessException extends FinancialException
{
    public function __construct()
    {
        parent::__construct(
            'REQUEST_IN_PROGRESS',
            'A request with this idempotency key is still being processed. Retry later.',
            409,
        );
    }
}
