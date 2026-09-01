<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\DTOs\PayoutRequest;
use App\Domain\Payments\DTOs\PayoutResponse;
use App\Domain\Payments\DTOs\PayoutVerificationResponse;

/**
 * Contract for payout providers (wallet -> bank transfers).
 *
 * Implementations must be idempotent with respect to
 * `PayoutRequest::$transactionReference`: re-submitting the same reference
 * must not cause a second external transfer.
 *
 * Payouts are almost always asynchronous on the Nigerian rails (NIP
 * settlement), so a successful API call usually yields an AMBIGUOUS outcome
 * ("accepted, pending") — the definitive outcome arrives via webhook or
 * `verify()`.
 */
interface PayoutProviderInterface
{
    public function payout(PayoutRequest $request): PayoutResponse;

    public function verify(string $providerReference): PayoutVerificationResponse;
}
