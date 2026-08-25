<?php

namespace App\Exceptions;

class UnknownLedgerAccountException extends FinancialException
{
    public function __construct(string $code)
    {
        parent::__construct('LEDGER_ACCOUNT_NOT_FOUND', "Unknown ledger account [{$code}].", 500);
    }
}
