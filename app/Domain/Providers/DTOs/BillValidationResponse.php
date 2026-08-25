<?php

namespace App\Domain\Providers\DTOs;

final class BillValidationResponse
{
    public function __construct(
        public readonly bool $valid,
        public readonly ?string $customerName = null,
        public readonly ?int $expectedAmount = null,
        public readonly ?string $errorMessage = null,
    ) {
    }
}
