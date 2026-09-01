<?php

namespace App\Infrastructure\Providers\Reloadly;

use App\Domain\GiftCards\Contracts\GiftCardProviderInterface;
use App\Domain\GiftCards\DTOs\GiftCardPurchaseRequest;
use App\Domain\GiftCards\DTOs\GiftCardPurchaseResponse;
use App\Domain\GiftCards\DTOs\GiftCardRedeemCode;
use App\Domain\Providers\Enums\ProviderOutcome;

/**
 * Reloadly Gift Cards API — see ReloadlyClient's docblock for the auth/
 * versioning notes.
 *
 * Reloadly's order call is synchronous and almost always resolves
 * immediately (SUCCESSFUL/FAILED in the same response) — there is no
 * TSQ-style aggregator-pending state the way Kuda's bill rail has.
 * AMBIGUOUS is reserved for the genuine case: the HTTP call itself timed
 * out or the connection dropped, so we don't know if Reloadly processed
 * it. `customIdentifier` is always our own transaction reference, so
 * verifyByReference() can recover the true outcome by looking the order
 * up on Reloadly's side rather than by a provider transaction id we may
 * never have received.
 */
final class ReloadlyGiftCardProvider implements GiftCardProviderInterface
{
    private const SUCCESS_STATUSES = ['SUCCESSFUL', 'SUCCESS', 'COMPLETED'];

    private const FAILURE_STATUSES = ['FAILED', 'UNSUCCESSFUL', 'REJECTED', 'CANCELLED', 'CANCELED', 'DECLINED'];

    public function __construct(private readonly ReloadlyClient $client)
    {
    }

    public function purchase(GiftCardPurchaseRequest $request): GiftCardPurchaseResponse
    {
        $payload = $this->client->post('/orders', [
            'productId' => $request->productId,
            'quantity' => 1,
            'unitPrice' => $request->unitPrice,
            'customIdentifier' => $request->transactionReference,
            'senderName' => $request->senderName,
            'preOrder' => false,
        ]);

        return $this->classify($payload);
    }

    public function verifyByReference(string $transactionReference): GiftCardPurchaseResponse
    {
        $matches = $this->client->get('/reports/transactions', [
            'customIdentifier' => $transactionReference,
            'size' => 1,
            'page' => 1,
        ]);

        $order = array_values(array_filter($matches, 'is_array'))[0] ?? null;

        if ($order === null) {
            // Nothing on Reloadly's side yet — could be "not indexed yet"
            // or "never received". Never guessed as failure; the next
            // reconciliation pass tries again.
            return new GiftCardPurchaseResponse(ProviderOutcome::Ambiguous);
        }

        return $this->classify($order);
    }

    public function redeemCode(string $providerReference): ?GiftCardRedeemCode
    {
        $payload = $this->client->get(
            "/orders/transactions/{$providerReference}/cards",
            [],
            acceptVersion: 'v2',
        );

        if (! isset($payload['cardNumber'])) {
            return null;
        }

        return new GiftCardRedeemCode(
            cardNumber: (string) $payload['cardNumber'],
            pinCode: isset($payload['pinCode']) ? (string) $payload['pinCode'] : null,
            redemptionUrl: isset($payload['redemptionUrl']) ? (string) $payload['redemptionUrl'] : null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function classify(array $payload): GiftCardPurchaseResponse
    {
        $status = strtoupper((string) ($payload['status'] ?? ''));
        $transactionId = isset($payload['transactionId']) ? (string) $payload['transactionId'] : null;

        if (in_array($status, self::SUCCESS_STATUSES, true)) {
            return new GiftCardPurchaseResponse(
                ProviderOutcome::DefinitiveSuccess,
                $transactionId,
                null,
                [
                    'reloadly_transaction_id' => $transactionId,
                    'reloadly_amount' => $payload['amount'] ?? null,
                    'reloadly_fee' => $payload['totalFee'] ?? null,
                ],
            );
        }

        if (in_array($status, self::FAILURE_STATUSES, true)) {
            return new GiftCardPurchaseResponse(
                ProviderOutcome::DefinitiveFailure,
                $transactionId,
                "Reloadly order {$status}",
            );
        }

        // Unknown/pending status but a transaction id exists — hold as
        // ambiguous rather than guess; the reference is kept so a later
        // verify pass can ask Reloadly directly instead of re-deriving it.
        return new GiftCardPurchaseResponse(ProviderOutcome::Ambiguous, $transactionId);
    }
}
