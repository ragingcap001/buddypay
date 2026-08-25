<?php

namespace App\Domain\Providers\DTOs;

use App\Domain\Providers\Enums\ProviderOutcome;

final class BillVerificationResponse
{
    public function __construct(
        public readonly ProviderOutcome $outcome,
        public readonly ?string $providerReference = null,
        public readonly ?string $errorMessage = null,
    ) {
    }
}
