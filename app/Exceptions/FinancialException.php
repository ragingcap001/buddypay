<?php

namespace App\Exceptions;

use Exception;

/**
 * Base class for domain/financial exceptions.
 *
 * These carry a stable machine-readable error code (used in the API error
 * envelope) and the HTTP status the exception should render as.
 */
class FinancialException extends Exception
{
    public function __construct(
        private readonly string $errorCode,
        string $message,
        private readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    public function httpStatusCode(): int
    {
        return $this->httpStatus;
    }
}
