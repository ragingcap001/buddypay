<?php

namespace App\Application\Commands;

use App\Domain\Transactions\Enums\TransactionType;

/**
 * Command object for initiating a wallet-funded bill payment
 * (airtime, data, electricity, cable TV, betting, transfer).
 *
 * @readonly
 */
final class InitiateBillPayment
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly int $userId,
        public readonly TransactionType $type,
        public readonly int $amountKobo,
        public readonly string $idempotencyKey,
        public readonly string $phoneNumber,
        public readonly ?string $provider = null,
        public readonly array $metadata = [],
    ) {
    }
}
