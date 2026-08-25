<?php

namespace App\Domain\Ledger\Enums;

enum LedgerAccountType: string
{
    case Asset = 'ASSET';
    case Liability = 'LIABILITY';
    case Equity = 'EQUITY';
    case Revenue = 'REVENUE';
    case Expense = 'EXPENSE';
}
