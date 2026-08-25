<?php

namespace App\Domain\Transactions\StateMachines;

use App\Domain\Transactions\Enums\TransactionStatus;
use App\Exceptions\InvalidStateTransitionException;

/**
 * Explicit transaction state machine.
 *
 *     INITIATED
 *         |
 *         v
 *     PENDING
 *         |
 *         v
 *     PROCESSING
 *      /--------\
 *     v          v
 *   SUCCESS   AMBIGUOUS
 *     |          |
 *     v          v
 *  COMPLETED  VERIFYING
 *              /-------\
 *             v         v
 *          SUCCESS    FAILED
 *
 * Terminal states (COMPLETED, FAILED, REVERSED) may not be left except
 * through a controlled compensating process.
 */
final class TransactionStateMachine
{
    /**
     * @var array<TransactionStatus, list<TransactionStatus>>
     */
    private const ALLOWED_TRANSITIONS = [
        TransactionStatus::Initiated => [TransactionStatus::Pending, TransactionStatus::Failed],
        TransactionStatus::Pending => [TransactionStatus::Processing, TransactionStatus::Failed],
        TransactionStatus::Processing => [TransactionStatus::Success, TransactionStatus::Failed, TransactionStatus::Ambiguous],
        TransactionStatus::Ambiguous => [TransactionStatus::Verifying, TransactionStatus::Failed],
        TransactionStatus::Verifying => [TransactionStatus::Success, TransactionStatus::Failed],
        TransactionStatus::Success => [TransactionStatus::Completed, TransactionStatus::Reversed],
        TransactionStatus::Completed => [],
        TransactionStatus::Failed => [],
        TransactionStatus::Reversed => [],
    ];

    public static function canTransition(TransactionStatus $from, TransactionStatus $to): bool
    {
        return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
    }

    public static function assertCanTransition(TransactionStatus $from, TransactionStatus $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new InvalidStateTransitionException($from->value, $to->value);
        }
    }

    public static function isTerminal(TransactionStatus $status): bool
    {
        return (self::ALLOWED_TRANSITIONS[$status] ?? []) === [];
    }
}
