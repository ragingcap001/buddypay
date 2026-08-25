<?php

namespace App\Domain\Transactions\Enums;

enum TransactionStatus: string
{
    case Initiated = 'INITIATED';
    case Pending = 'PENDING';
    case Processing = 'PROCESSING';
    case Success = 'SUCCESS';
    case Ambiguous = 'AMBIGUOUS';
    case Verifying = 'VERIFYING';
    case Completed = 'COMPLETED';
    case Failed = 'FAILED';
    case Reversed = 'REVERSED';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Reversed], true);
    }
}
