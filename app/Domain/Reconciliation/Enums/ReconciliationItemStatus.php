<?php

namespace App\Domain\Reconciliation\Enums;

enum ReconciliationItemStatus: string
{
    case Matched = 'MATCHED';
    case MissingProviderRecord = 'MISSING_PROVIDER_RECORD';
    case MissingInternalRecord = 'MISSING_INTERNAL_RECORD';
    case AmountMismatch = 'AMOUNT_MISMATCH';
    case StatusMismatch = 'STATUS_MISMATCH';
    case DuplicateProviderTransaction = 'DUPLICATE_PROVIDER_TRANSACTION';
    case Unresolved = 'UNRESOLVED';
}
