<?php

namespace App\Infrastructure\Providers\Monnify;

use App\Domain\Payments\Contracts\PaymentProviderInterface;
use App\Domain\Payments\DTOs\PaymentChargeRequest;
use App\Domain\Payments\DTOs\PaymentChargeResponse;
use App\Domain\Payments\DTOs\PaymentVerificationResponse;
use App\Domain\Providers\Enums\ProviderOutcome;

/**
 * Monnify wallet-funding provider — one-time payment (bank transfer /
 * card / USSD) initialised server-side.
 *
 * Flow:
 *   1. `charge()` initializes a Monnify transaction whose `paymentReference`
 *      is the platform transaction reference; Monnify returns a checkout
 *      URL and (for bank transfer) a virtual account to pay into.
 *   2. The customer pays asynchronously — `charge()` therefore returns
 *      AMBIGUOUS ("checkout ready, awaiting payment").
 *   3. Settlement arrives via the `SUCCESSFUL_TRANSACTION` webhook (matched
 *      on `paymentReference`) or `verify()` (GET /api/v2/charges/
 *      transactions/{reference}).
 *
 * Note: the Customer Reserved Account API (persistent virtual accounts per
 * customer) is available on the client (`createReservedAccount`) for the
 * recurring-deposit product; the one-time flow above keeps a 1:1 mapping
 * to platform transactions.
 */
final class MonnifyPaymentProvider implements PaymentProviderInterface
{
    public function __construct(private readonly MonnifyClient $client)
    {
    }

    public function charge(PaymentChargeRequest $request): PaymentChargeResponse
    {
        $customerName = (string) ($request->metadata['customer_name'] ?? 'Customer');
        $customerEmail = $request->customerEmail ?? (string) ($request->metadata['customer_email'] ?? '');

        $result = $this->client->initializeTransaction(
            $request->amount,
            $request->transactionReference,
            $customerName,
            $customerEmail !== '' ? $customerEmail : null,
            'Wallet funding',
        );

        // The hosted-checkout response carries Monnify's
        // transactionReference (the join key for verify + webhooks) and
        // the checkoutUrl the customer is redirected to.
        return new PaymentChargeResponse(
            ProviderOutcome::Ambiguous,
            (string) ($result['transactionReference'] ?? $request->transactionReference),
            'Awaiting payment',
            [
                'payment_url' => (string) ($result['checkoutUrl'] ?? ''),
                'account_number' => (string) ($result['accountNumber'] ?? ''),
                'bank' => (string) ($result['bankName'] ?? 'Monnify partner bank'),
                'bank_code' => (string) ($result['bankCode'] ?? ''),
                'amount' => $request->amount,
            ],
        );
    }

    public function verify(string $providerReference): PaymentVerificationResponse
    {
        $result = $this->client->getTransaction($providerReference);

        // The authoritative field is `paymentStatus` (not `status`).
        // PAID: fulfil. OVERPAID: fulfil (refund/credit the excess).
        // PARTIALLY_PAID / FAILED: never fulfil. PENDING: re-poll/webhook.
        $status = strtoupper((string) ($result['paymentStatus'] ?? $result['status'] ?? ''));

        return match ($status) {
            'PAID' => new PaymentVerificationResponse(
                ProviderOutcome::DefinitiveSuccess,
                $providerReference,
                null,
            ),
            'OVERPAID' => new PaymentVerificationResponse(
                ProviderOutcome::DefinitiveSuccess,
                $providerReference,
                'Customer overpaid — review the excess for a refund',
            ),
            'FAILED', 'PARTIALLY_PAID', 'EXPIRED', 'CANCELED' => new PaymentVerificationResponse(
                ProviderOutcome::DefinitiveFailure,
                null,
                "Monnify transaction is {$status}",
            ),
            default => new PaymentVerificationResponse(
                ProviderOutcome::Ambiguous,
                $providerReference,
                'Monnify transaction is still pending',
            ),
        };
    }
}
