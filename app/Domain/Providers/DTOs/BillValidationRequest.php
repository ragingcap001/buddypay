<?php

namespace App\Domain\Providers\DTOs;

use App\Domain\Transactions\Enums\TransactionType;

final class BillValidationRequest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public readonly string $providerName,
        public readonly TransactionType $category,
        public readonly string $phoneNumber,
        public readonly array $metadata = [],
    ) {
    }
}
