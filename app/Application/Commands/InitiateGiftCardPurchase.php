<?php

namespace App\Application\Commands;

/**
 * @readonly
 */
final class InitiateGiftCardPurchase
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly int $userId,
        public readonly int $productId,
        public readonly float $denomination,
        public readonly int $totalKobo,
        public readonly string $idempotencyKey,
        public readonly array $metadata = [],
    ) {
    }
}
