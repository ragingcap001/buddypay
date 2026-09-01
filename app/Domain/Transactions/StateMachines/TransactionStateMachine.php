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
     * Keyed by the enum's backing value, not the case itself: PHP array
     * keys may only be int|string, so enum cases here are a fatal
     * TypeError on the first lookup.
     *
     * @var array<string, list<TransactionStatus>>
     */
    private const ALLOWED_TRANSITIONS = [
        TransactionStatus::Initiated->value => [TransactionStatus::Pending, TransactionStatus::Failed],
        TransactionStatus::Pending->value => [TransactionStatus::Processing, TransactionStatus::Failed],
        TransactionStatus::Processing->value => [TransactionStatus::Success, TransactionStatus::Failed, TransactionStatus::Ambiguous],
        TransactionStatus::Ambiguous->value => [TransactionStatus::Verifying, TransactionStatus::Failed],
        TransactionStatus::Verifying->value => [TransactionStatus::Success, TransactionStatus::Failed],
        TransactionStatus::Success->value => [TransactionStatus::Completed, TransactionStatus::Reversed],
        TransactionStatus::Completed->value => [],
        TransactionStatus::Failed->value => [],
        TransactionStatus::Reversed->value => [],
    ];

    public static function canTransition(TransactionStatus $from, TransactionStatus $to): bool
    {
        return in_array($to, self::ALLOWED_TRANSITIONS[$from->value] ?? [], true);
    }

    public static function assertCanTransition(TransactionStatus $from, TransactionStatus $to): void
    {
        if (! self::canTransition($from, $to)) {
            throw new InvalidStateTransitionException($from->value, $to->value);
        }
    }

    public static function isTerminal(TransactionStatus $status): bool
    {
        return (self::ALLOWED_TRANSITIONS[$status->value] ?? []) === [];
    }
}
