<?php

namespace App\Infrastructure\Providers\Monnify;

use App\Domain\Payments\Contracts\OtpAuthorizablePayoutProvider;
use App\Domain\Payments\DTOs\PayoutRequest;
use App\Domain\Payments\DTOs\PayoutResponse;
use App\Domain\Payments\DTOs\PayoutVerificationResponse;
use App\Domain\Providers\Enums\ProviderOutcome;

/**
 * Monnify payout provider — single-transfer disbursements from the
 * platform's Monnify disbursement wallet to any Nigerian bank account.
 *
 * Flow:
 *   1. `payout()` initiates an async single transfer (MFA is enabled by
 *      default on Monnify disbursement accounts, so the usual response is
 *      PENDING_AUTHORIZATION → AMBIGUOUS).
 *   2. The operator submits the OTP from the Monnify account's registered
 *      email: `php artisan payouts:authorize {reference} {otp}`.
 *   3. Final status also arrives via the `SUCCESSFUL_DISBURSEMENT` /
 *      `FAILED_DISBURSEMENT` webhooks or `verify()`
 *      (GET /api/v2/disbursements/single/summary).
 */
final class MonnifyPayoutProvider implements OtpAuthorizablePayoutProvider
{
    public function __construct(private readonly MonnifyClient $client)
    {
    }

    public function payout(PayoutRequest $request): PayoutResponse
    {
        $result = $this->client->singleTransfer(
            $request->amountKobo,
            $request->transactionReference,
            $request->bankCode,
            $request->accountNumber,
            $request->accountName,
            $request->narration,
        );

        $status = strtoupper((string) ($result['status'] ?? ''));
        $providerReference = (string) ($result['reference'] ?? $request->transactionReference);

        return match ($status) {
            'SUCCESS' => new PayoutResponse(ProviderOutcome::DefinitiveSuccess, $providerReference, null),
            'FAILED', 'EXPIRED', 'REVERSED' => new PayoutResponse(
                ProviderOutcome::DefinitiveFailure,
                null,
                "Monnify disbursement failed ({$status})",
            ),
            // PENDING_AUTHORIZATION (MFA) / in-progress — settled via OTP
            // + webhook or the status endpoint.
            default => new PayoutResponse(
                ProviderOutcome::Ambiguous,
                $providerReference,
                'Payout awaiting authorization/processing'
                .($status === 'PENDING_AUTHORIZATION'
                    ? ' (MFA: submit the OTP with `php artisan payouts:authorize`)'
                    : ''),
            ),
        };
    }

    /**
     * Submit the MFA OTP for a PENDING_AUTHORIZATION disbursement.
     */
    public function authorize(string $providerReference, string $otp): PayoutResponse
    {
        $result = $this->client->authorizeSingleTransfer($providerReference, $otp);
        $status = strtoupper((string) ($result['status'] ?? ''));

        return match ($status) {
            'SUCCESS' => new PayoutResponse(ProviderOutcome::DefinitiveSuccess, $providerReference, null),
            'FAILED', 'EXPIRED', 'REVERSED' => new PayoutResponse(
                ProviderOutcome::DefinitiveFailure,
                null,
                "Monnify disbursement failed after OTP authorization ({$status})",
            ),
            default => new PayoutResponse(
                ProviderOutcome::Ambiguous,
                $providerReference,
                "Monnify disbursement still processing after OTP authorization ({$status})",
            ),
        };
    }

    public function verify(string $providerReference): PayoutVerificationResponse
    {
        $result = $this->client->getSingleTransferStatus($providerReference);
        $status = strtoupper((string) ($result['status'] ?? ''));

        return match ($status) {
            'SUCCESS', 'COMPLETED' => new PayoutVerificationResponse(
                ProviderOutcome::DefinitiveSuccess,
                $providerReference,
                null,
            ),
            'FAILED', 'EXPIRED', 'REVERSED' => new PayoutVerificationResponse(
                ProviderOutcome::DefinitiveFailure,
                null,
                "Monnify disbursement is {$status}",
            ),
            default => new PayoutVerificationResponse(
                ProviderOutcome::Ambiguous,
                $providerReference,
                'Monnify disbursement is still pending',
            ),
        };
    }
}
