<?php

namespace App\Domain\Transactions\Support;

use App\Domain\Transactions\Enums\TransactionStatus;

/**
 * Maps the internal state-machine's status vocabulary (INITIATED,
 * PENDING, PROCESSING, SUCCESS, AMBIGUOUS, VERIFYING, COMPLETED, FAILED,
 * REVERSED) to the mobile contract's lowercase, coarser one
 * (approved/failed/reversed/pending). Deliberately one-way: the richer
 * internal vocabulary is what drives real behaviour (ledger posting,
 * reservation release, reconciliation) and stays exactly as it is.
 */
final class MobileTransactionStatus
{
    public static function forDisplay(TransactionStatus $status): string
    {
        return match ($status) {
            TransactionStatus::Completed => 'approved',
            TransactionStatus::Failed => 'failed',
            TransactionStatus::Reversed => 'reversed',
            default => 'pending',
        };
    }
}
