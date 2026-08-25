<?php

namespace App\Domain\Payments\DTOs;

use App\Domain\Providers\Enums\ProviderOutcome;

final class PaymentVerificationResponse
{
    public function __construct(
        public readonly ProviderOutcome $outcome,
        public readonly ?string $providerReference = null,
        public readonly ?string $errorMessage = null,
    ) {
    }
}
