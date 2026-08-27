<?php

namespace App\Domain\Providers\DTOs;

use App\Domain\Providers\Enums\ProviderOutcome;

final class BillPurchaseResponse
{
    /**
     * @param  array<string, mixed>  $providerMetadata  Provider-specific data to persist on the transaction metadata (e.g. a short provider requestRef)
     */
    public function __construct(
        public readonly ProviderOutcome $outcome,
        public readonly ?string $providerReference = null,
        public readonly ?int $amount = null,
        public readonly ?string $errorMessage = null,
        public readonly array $providerMetadata = [],
    ) {
    }
}
