<?php

namespace App\Application\Commands;

/**
 * Command object for a wallet -> bank payout (bank transfer out).
 *
 * @readonly
 */
final class InitiateBankTransfer
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $amountKobo,
        public readonly string $idempotencyKey,
        public readonly string $bankCode,
        public readonly string $accountNumber,
        public readonly string $accountName,
        public readonly ?string $narration = null,
        public readonly ?string $provider = null,
        public readonly array $metadata = [],
    ) {
    }
}
