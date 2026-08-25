<?php

namespace App\Domain\Providers\DTOs;

use App\Domain\Transactions\Enums\TransactionType;

final class BillPurchaseRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $providerName,
        public readonly TransactionType $category,
        public readonly string $phoneNumber,
        public readonly int $amount,
        public readonly string $transactionReference,
        public readonly array $metadata = [],
    ) {
    }
}
