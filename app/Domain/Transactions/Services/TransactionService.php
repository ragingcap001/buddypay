<?php

namespace App\Domain\Transactions\Services;

use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Enums\TransactionType;
use App\Domain\Transactions\StateMachines\TransactionStateMachine;
use App\Models\Transaction;
use App\Models\TransactionEvent;

/**
 * Primitives for creating and transitioning transactions.
 *
 * Every transition is recorded in the append-only transaction_events table
 * so the full lifecycle of a transaction can be reconstructed.
 *
 * All methods must be called inside a database transaction.
 */
final class TransactionService
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Transaction
    {
        return Transaction::create($attributes);
    }

    /**
     * Atomically transition a transaction to a new status, re-locking the
     * row to guard against concurrent state changes.
     */
    public function transition(Transaction $transaction, TransactionStatus $to, string $reason, array $metadata = []): Transaction
    {
        $locked = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();

        $from = TransactionStatus::from($locked->status);
        TransactionStateMachine::assertCanTransition($from, $to);

        $locked->status = $to->value;

        if ($to === TransactionStatus::Completed || $to === TransactionStatus::Failed || $to === TransactionStatus::Reversed) {
            $locked->completed_at = now();
        }

        $locked->save();

        TransactionEvent::create([
            'transaction_id' => $locked->id,
            'from_status' => $from->value,
            'to_status' => $to->value,
            'reason' => $reason,
            'metadata' => $metadata,
        ]);

        return $locked;
    }

    public function findByReference(string $reference): Transaction
    {
        return Transaction::where('reference', $reference)->firstOrFail();
    }

    public function forUser(int $userId, int $limit = 50): \Illuminate\Database\Eloquent\Collection
    {
        return Transaction::where('user_id', $userId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Calculate the fee (in kobo) for a transaction type using integer
     * arithmetic only: basis points of the amount plus an optional flat fee.
     */
    public function calculateFee(TransactionType $type, int $amountKobo): int
    {
        $key = strtolower($type->value);
        $config = (array) config("ase.fees.{$key}", []);

        $bps = (int) ($config['bps'] ?? 0);
        $flat = (int) ($config['flat'] ?? 0);

        return intdiv($amountKobo * $bps, 10000) + $flat;
    }
}
