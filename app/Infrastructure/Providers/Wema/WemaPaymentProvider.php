<?php

namespace App\Infrastructure\Providers\Wema;

use App\Domain\Payments\Contracts\PaymentProviderInterface;
use App\Domain\Payments\DTOs\PaymentChargeRequest;
use App\Domain\Payments\DTOs\PaymentChargeResponse;
use App\Domain\Payments\DTOs\PaymentVerificationResponse;
use App\Domain\Providers\Enums\ProviderOutcome;

/**
 * Wema (ALAT) wallet-funding provider — bank transfer IN via Wema payment
 * requests.
 *
 * Flow:
 *   1. `charge()` creates a Wema payment request for the exact amount; Wema
 *      issues a virtual account the customer transfers to.
 *   2. The deposit itself is asynchronous — `charge()` therefore returns
 *      AMBIGUOUS ("virtual account ready, awaiting transfer").
 *   3. Settlement arrives via the provider webhook or `verify()`
 *      (GET /payments/v1/paymentrequests/{reference}).
 */
final class WemaPaymentProvider implements PaymentProviderInterface
{
    public function __construct(private readonly WemaClient $client)
    {
    }

    public function charge(PaymentChargeRequest $request): PaymentChargeResponse
    {
        $result = $this->client->createPaymentRequest(
            $request->amount,
            $request->transactionReference,
            'Wallet funding',
            $this->customerDetails($request),
        );

        return new PaymentChargeResponse(
            ProviderOutcome::Ambiguous,
            (string) ($result['reference'] ?? $request->transactionReference),
            'Virtual account created; awaiting bank transfer',
            [
                'account_number' => (string) ($result['virtualAccount'] ?? ''),
                'bank' => (string) ($result['virtualBank'] ?? 'Wema Bank'),
                'bank_code' => WemaClient::BANK_CODE,
                'amount' => $request->amount,
                'ttl_seconds' => 3600,
            ],
        );
    }

    public function verify(string $providerReference): PaymentVerificationResponse
    {
        $result = $this->client->getPaymentRequest($providerReference);
        $status = strtoupper((string) ($result['status'] ?? ''));

        return match ($status) {
            'COMPLETED', 'SUCCESS' => new PaymentVerificationResponse(
                ProviderOutcome::DefinitiveSuccess,
                $providerReference,
                null,
            ),
            'CANCELLED', 'EXPIRED', 'FAILED' => new PaymentVerificationResponse(
                ProviderOutcome::DefinitiveFailure,
                null,
                "Wema payment request is {$status}",
            ),
            default => new PaymentVerificationResponse(
                ProviderOutcome::Ambiguous,
                $providerReference,
                'Wema payment request is still pending',
            ),
        };
    }

    /**
     * Best-effort mapping of platform metadata onto Wema's customer object.
     *
     * @return array<string, string>
     */
    private function customerDetails(PaymentChargeRequest $request): array
    {
        $details = [];

        $name = (string) ($request->metadata['customer_name'] ?? '');

        if ($name !== '') {
            $parts = explode(' ', $name, 2);
            $details['firstName'] = $parts[0];
            $details['lastName'] = $parts[1] ?? '';
        }

        $email = $request->customerEmail ?? (string) ($request->metadata['customer_email'] ?? '');

        if ($email !== '') {
            $details['email'] = $email;
        }

        $phone = (string) ($request->metadata['customer_phone'] ?? '');

        if ($phone !== '') {
            $details['mobileNumber'] = $phone;
        }

        return $details;
    }
}
