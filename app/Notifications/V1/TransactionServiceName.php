<?php

namespace App\Notifications\V1;

use App\Models\Transaction;

/**
 * Best-effort human-readable service name for a transaction ("IKEDC
 * PREPAID", "MTN VTU"). Centralised here so the airtime/data/electricity
 * /betting/cable purchase services (not yet built) only need to agree on
 * one metadata key — `service_name` — for every notification to pick it
 * up automatically, instead of each notification class guessing.
 */
final class TransactionServiceName
{
    public static function forNotification(Transaction $transaction): string
    {
        $metadata = (array) $transaction->metadata;

        return (string) ($metadata['service_name'] ?? $transaction->provider ?? ucfirst(strtolower($transaction->type)));
    }
}
