<?php

namespace App\Domain\Payments\Contracts;

use App\Domain\Payments\DTOs\PayoutResponse;

/**
 * Optional capability: payout providers that require an OTP step after
 * initiation (Monnify disbursement MFA, enabled by default on every
 * disbursement account).
 *
 * The flow is:
 *   1. `payout()` returns AMBIGUOUS with status PENDING_AUTHORIZATION
 *   2. the operator receives the OTP (sent to the account's registered
 *      email) and runs `php artisan payouts:authorize {reference} {otp}`
 *   3. `authorize()` returns the definitive outcome
 */
interface OtpAuthorizablePayoutProvider extends PayoutProviderInterface
{
    public function authorize(string $providerReference, string $otp): PayoutResponse;
}
