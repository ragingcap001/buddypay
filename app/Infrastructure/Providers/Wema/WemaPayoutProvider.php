<?php

namespace App\Infrastructure\Providers\Wema;

use App\Domain\Payments\Contracts\PayoutProviderInterface;
use App\Domain\Payments\DTOs\PayoutRequest;
use App\Domain\Payments\DTOs\PayoutResponse;
use App\Domain\Payments\DTOs\PayoutVerificationResponse;
use App\Domain\Providers\Enums\ProviderOutcome;
use App\Exceptions\ProviderDeclinedException;

/**
 * Wema (ALAT) payout provider — bank transfers OUT from the merchant's
 * profiled ALAT source account to any Nigerian bank account (NIP).
 *
 * Flow:
 *   1. NIP name enquiry confirms the destination account (a mismatched name
 *      is rejected before any money moves).
 *   2. `POST /payouts/v2/payouts` accepts the transfer (PENDING).
 *   3. Settlement arrives via the provider webhook or `verify()`
 *      (GET /payouts/v2/payouts/{reference}).
 */
final class WemaPayoutProvider implements PayoutProviderInterface
{
    public function __construct(private readonly WemaClient $client)
    {
    }

    public function payout(PayoutRequest $request): PayoutResponse
    {
        $enquiry = $this->client->nameEnquiry($request->bankCode, $request->accountNumber);
        $beneficiaryName = strtoupper(trim((string) ($enquiry['accountName'] ?? '')));

        if ($beneficiaryName === '') {
            throw new ProviderDeclinedException(
                'BENEFICIARY_NOT_FOUND',
                "Wema could not confirm account [{$request->accountNumber}] at bank [{$request->bankCode}].",
                422,
            );
        }

        // Send the name Wema itself reports — the authoritative one — rather
        // than the client-supplied spelling.
        $result = $this->client->payout(
            $request->amountKobo,
            $request->transactionReference,
            $request->bankCode,
            $request->accountNumber,
            $beneficiaryName,
            (string) ($request->narration ?? 'Transfer'),
        );

        return new PayoutResponse(
            ProviderOutcome::Ambiguous,
            (string) ($result['reference'] ?? $request->transactionReference),
            'Payout accepted; awaiting bank confirmation',
        );
    }

    public function verify(string $providerReference): PayoutVerificationResponse
    {
        $result = $this->client->getPayout($providerReference);
        $status = strtoupper((string) ($result['status'] ?? ''));

        return match ($status) {
            'COMPLETED', 'SUCCESS' => new PayoutVerificationResponse(
                ProviderOutcome::DefinitiveSuccess,
                $providerReference,
                null,
            ),
            'CANCELLED', 'REVERSED', 'FAILED' => new PayoutVerificationResponse(
                ProviderOutcome::DefinitiveFailure,
                null,
                "Wema payout is {$status}",
            ),
            default => new PayoutVerificationResponse(
                ProviderOutcome::Ambiguous,
                $providerReference,
                'Wema payout is still pending',
            ),
        };
    }
}
