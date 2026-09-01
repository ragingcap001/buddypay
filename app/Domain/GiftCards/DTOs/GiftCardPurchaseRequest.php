<?php

namespace App\Domain\GiftCards\DTOs;

final class GiftCardPurchaseRequest
{
    public function __construct(
        public readonly string $providerName,
        public readonly int $productId,
        public readonly float $unitPrice,
        public readonly string $senderName,
        public readonly string $transactionReference,
    ) {
    }
}
