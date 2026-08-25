<?php

namespace App\Exceptions;

/**
 * The provider DEFINITIVELY rejected the request before any transaction was
 * initiated (local pre-validation failure, 401/403/400/404 from the bank,
 * ...). Unlike a timeout or 5xx, no external transaction may have been
 * created, so the outcome classifier maps this to DEFINITIVE_FAILURE and the
 * transaction fails cleanly instead of sitting in VERIFYING.
 */
class ProviderDeclinedException extends FinancialException
{
    public function __construct(string $code, string $message, int $httpStatus = 422)
    {
        parent::__construct($code, $message, $httpStatus);
    }
}
