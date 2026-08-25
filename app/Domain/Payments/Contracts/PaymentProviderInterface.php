<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\DTOs\PaymentChargeRequest;
use App\Domain\Payments\DTOs\PaymentChargeResponse;
use App\Domain\Payments\DTOs\PaymentVerificationResponse;

/**
 * Contract for wallet-funding payment providers (cards, bank transfer,
 * USSD, ...). Implementations must be idempotent with respect to
 * transactionReference.
 */
interface PaymentProviderInterface
{
    public function charge(PaymentChargeRequest $request): PaymentChargeResponse;

    public function verify(string $providerReference): PaymentVerificationResponse;
}
