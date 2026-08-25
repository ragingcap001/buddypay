<?php

namespace App\Exceptions;

class IdempotencyKeyReusedException extends FinancialException
{
    public function __construct()
    {
        parent::__construct(
            'IDEMPOTENCY_KEY_REUSED',
            'This idempotency key has already been used with a different request.',
            409,
        );
    }
}
