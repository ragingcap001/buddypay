<?php

namespace App\Domain\Providers\DTOs;

use App\Domain\Providers\Enums\ProviderOutcome;

final class BillPurchaseResponse
{
    public function __construct(
        public readonly ProviderOutcome $outcome,
        public readonly ?string $providerReference = null,
        public readonly ?int $amount = null,
        public readonly ?string $errorMessage = null,
    ) {
    }
}
