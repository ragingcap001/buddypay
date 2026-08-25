<?php

namespace App\Domain\Payments\DTOs;

final class PaymentChargeRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $providerName,
        public readonly int $amount,
        public readonly string $transactionReference,
        public readonly ?string $customerEmail = null,
        public readonly array $metadata = [],
    ) {
    }
}
