<?php

namespace App\Exceptions;

class RiskBlockedException extends FinancialException
{
    public function __construct(string $reason)
    {
        parent::__construct('RISK_BLOCKED', "Transaction blocked by risk engine: {$reason}.", 403);
    }
}
