<?php

namespace App\Domain\Ledger\Enums;

enum EntryDirection: string
{
    case Debit = 'DEBIT';
    case Credit = 'CREDIT';
}
