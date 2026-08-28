<?php

namespace App\Domain\GiftCards\DTOs;

use App\Domain\Providers\Enums\ProviderOutcome;

final class GiftCardPurchaseResponse
{
    /**
     * @param  array<string, mixed>  $providerMetadata
     */
    public function __construct(
        public readonly ProviderOutcome $outcome,
        public readonly ?string $providerReference = null,
        public readonly ?string $errorMessage = null,
        public readonly array $providerMetadata = [],
    ) {
    }
}
