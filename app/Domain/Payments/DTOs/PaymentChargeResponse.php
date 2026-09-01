<?php

namespace App\Domain\Payments\DTOs;

use App\Domain\Providers\Enums\ProviderOutcome;

/**
 * Result of a wallet-funding charge.
 *
 * `paymentDetails` carries provider-specific instructions the customer needs
 * to complete the deposit (e.g. the virtual account number to transfer to, a
 * checkout URL, ...). It is stored on the transaction metadata and returned
 * by the API so the client can display it.
 *
 * @readonly
 */
final class PaymentChargeResponse
{
    /**
     * @param  array<string, mixed>  $paymentDetails  Customer-facing deposit instructions
     */
    public function __construct(
        public readonly ProviderOutcome $outcome,
        public readonly ?string $providerReference = null,
        public readonly ?string $errorMessage = null,
        public readonly array $paymentDetails = [],
    ) {
    }
}
