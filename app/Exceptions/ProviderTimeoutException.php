<?php

namespace App\Exceptions;

class ProviderTimeoutException extends FinancialException
{
    public function __construct(string $provider)
    {
        parent::__construct(
            'PROVIDER_TIMEOUT',
            "Provider [{$provider}] timed out. The transaction outcome is ambiguous and will be verified.",
            504,
        );
    }
}
