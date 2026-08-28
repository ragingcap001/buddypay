<?php

namespace App\Console\Commands;

use App\Models\IdempotencyKey;
use Illuminate\Console\Command;

/**
 * Deletes idempotency keys past their expiry — nothing previously did
 * this, so the table grew without bound and (before begin() enforced
 * expires_at itself) a COMPLETED key's stored response could be replayed
 * indefinitely regardless of the configured ttl_days.
 */
class SweepIdempotencyKeys extends Command
{
    protected $signature = 'idempotency:sweep';

    protected $description = 'Delete expired idempotency keys';

    public function handle(): int
    {
        // Plain bulk delete — no per-row side effects to bound, unlike
        // wallets:expire-reservations, so no batching is needed here.
        $count = IdempotencyKey::whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();

        if ($count > 0) {
            $this->info("Deleted {$count} expired idempotency key(s).");
        }

        return self::SUCCESS;
    }
}
