<?php

namespace App\Exceptions;

class LedgerNotBalancedException extends FinancialException
{
    public function __construct(int $totalDebits, int $totalCredits)
    {
        parent::__construct(
            'LEDGER_NOT_BALANCED',
            "Ledger transaction does not balance: debits {$totalDebits} != credits {$totalCredits}.",
            500,
        );
    }
}
