<?php

namespace App\Domain\Providers\DTOs;

use App\Domain\Transactions\Enums\TransactionType;

final class BillVerificationRequest
{
    public function __construct(
        public readonly string $providerName,
        public readonly TransactionType $category,
        public readonly string $transactionReference,
        public readonly ?string $providerReference = null,
    ) {
    }
}
