<?php

namespace App\Application\Commands;

/**
 * Command object for funding a wallet through a payment provider.
 *
 * @readonly
 */
final class FundWallet
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $amountKobo,
        public readonly string $idempotencyKey,
        public readonly string $method = 'mock_bank',
        public readonly ?string $provider = null,
        public readonly array $metadata = [],
    ) {
    }
}
