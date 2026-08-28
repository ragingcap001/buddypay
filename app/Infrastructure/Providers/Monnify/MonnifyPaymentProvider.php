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
 * Flow (two calls — the first does NOT return account details; only the
 * second does):
 *   1. `initializeTransaction()` opens the transaction; Monnify returns
 *      `transactionReference` (the join key for step 2, verify, and
 *      webhooks) and a `checkoutUrl`. No account number here — the
 *      contract's own field names for this were never actually reachable
 *      before this fix.
 *   2. `initBankTransferPayment(transactionReference)` — "Pay With Bank
 *      Transfer" — generates the one-time dynamic account/bank/expiry for
 *      THIS transaction. No BVN required (unlike Customer Reserved
 *      Accounts, which mint a *persistent* per-customer account and are a
 *      different product entirely — not used here).
 *   3. The customer pays asynchronously — `charge()` therefore returns
 *      AMBIGUOUS ("account ready, awaiting payment").
 *   4. Settlement arrives via webhook (matched on `paymentReference`) or
 *      `verify()` (GET /api/v2/merchant/transactions/query).
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

        $init = $this->client->initializeTransaction(
            $request->amount,
            $request->transactionReference,
            $customerName,
            $customerEmail !== '' ? $customerEmail : null,
            'Wallet funding',
        );

        $transactionReference = (string) ($init['transactionReference'] ?? $request->transactionReference);
        $account = $this->client->initBankTransferPayment($transactionReference);

        return new PaymentChargeResponse(
            ProviderOutcome::Ambiguous,
            $transactionReference,
            'Awaiting payment',
            [
                'payment_url' => (string) ($init['checkoutUrl'] ?? ''),
                'account_number' => (string) ($account['accountNumber'] ?? ''),
                'account_name' => (string) ($account['accountName'] ?? ''),
                'bank' => (string) ($account['bankName'] ?? 'Monnify partner bank'),
                'bank_code' => (string) ($account['bankCode'] ?? ''),
                'account_duration_seconds' => (int) ($account['accountDurationSeconds'] ?? 0),
                'expires_at' => (string) ($account['expiresOn'] ?? ''),
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
