<?php

namespace App\Domain\Providers\Services;

use App\Domain\Providers\DTOs\NormalizedWebhookEvent;
use App\Domain\Transactions\Enums\TransactionType;
use App\Models\Transaction;
use Illuminate\Support\Str;

/**
 * Translates each provider's raw webhook payload into the platform's
 * normalized event vocabulary.
 *
 * Supported providers:
 *  - monnify  { eventType: SUCCESSFUL_TRANSACTION | UNSUCCESSFUL_TRANSACTION
 *               | SUCCESSFUL_DISBURSEMENT | FAILED_DISBURSEMENT,
 *               eventData: { ... } }
 *             Collections carry `paymentReference` (the platform reference
 *             we passed as the Monnify payment reference); disbursements
 *             carry `reference` (the platform reference we passed as the
 *             transfer reference) plus Monnify's `transactionReference`.
 *  - wema     ALAT callback: { title, message, data: { status, message,
 *               narration, transactionReference, platformTransactionReference } }
 *             `transactionReference` is the platform reference we passed on
 *             the payment request / payout. A PENDING callback is ignored
 *             (ack-only). The generic { event_type, reference, ... } shape
 *             is also accepted for tooling.
 *  - default  generic shape { event_type, event_id, reference,
 *             provider_reference, error } (used by the mock provider).
 */
final class ProviderWebhookNormalizer
{
    public function normalize(string $providerName, array $payload): NormalizedWebhookEvent
    {
        return match ($providerName) {
            'monnify' => $this->normalizeMonnify($payload),
            'wema' => $this->normalizeWema($payload),
            default => $this->normalizeGeneric($payload),
        };
    }

    private function normalizeMonnify(array $payload): NormalizedWebhookEvent
    {
        $rawEventType = (string) ($payload['eventType'] ?? '');
        $data = (array) ($payload['eventData'] ?? []);

        $providerReference = (string) ($data['transactionReference'] ?? '');
        $isDisbursement = str_contains($rawEventType, 'DISBURSEMENT');
        $succeeded = str_starts_with($rawEventType, 'SUCCESSFUL');

        $reference = $isDisbursement
            ? (string) ($data['reference'] ?? $providerReference)
            : (string) (($data['paymentReference'] ?? ($data['product']['reference'] ?? $providerReference)));

        $base = $isDisbursement ? 'payout' : 'payment';

        return new NormalizedWebhookEvent(
            eventType: $succeeded ? $base.'.success' : $base.'.failed',
            reference: $reference,
            providerReference: $providerReference !== '' ? $providerReference : null,
            error: $succeeded ? null : (string) ($data['paymentDescription'] ?? "Monnify reported a failed {$base}"),
            eventId: $rawEventType.'|'.($providerReference !== '' ? $providerReference : $reference),
        );
    }

    private function normalizeWema(array $payload): NormalizedWebhookEvent
    {
        // ALAT callback model: { title, message, data: { status, ... } }
        if (isset($payload['data']['transactionReference'])) {
            $data = (array) $payload['data'];
            $status = strtoupper((string) ($data['status'] ?? ''));
            $reference = (string) ($data['transactionReference'] ?? '');
            $providerReference = (string) ($data['platformTransactionReference'] ?? '');
            $message = (string) ($data['message'] ?? 'Wema callback');

            return new NormalizedWebhookEvent(
                eventType: match ($status) {
                    'SUCCESSFUL', 'SUCCESS', 'COMPLETED' => $this->flowFor($reference).'.success',
                    'FAILED', 'CANCELED', 'CANCELLED' => $this->flowFor($reference).'.failed',
                    default => NormalizedWebhookEvent::IGNORED, // PENDING — ack only
                },
                reference: $reference,
                providerReference: $providerReference !== '' ? $providerReference : null,
                error: $message,
                eventId: 'wema-callback|'.$reference.'|'.$status,
            );
        }

        return $this->normalizeGeneric($payload);
    }

    private function normalizeGeneric(array $payload): NormalizedWebhookEvent
    {
        $eventType = (string) ($payload['event_type'] ?? 'unknown');
        $eventId = (string) ($payload['event_id'] ?? (string) Str::ulid());

        return new NormalizedWebhookEvent(
            eventType: $eventType,
            reference: (string) ($payload['reference'] ?? ''),
            providerReference: isset($payload['provider_reference']) ? (string) $payload['provider_reference'] : null,
            error: isset($payload['error']) ? (string) $payload['error'] : null,
            eventId: $eventId,
        );
    }

    /**
     * A deposit notification settles a WALLET_FUNDING transaction; a payout
     * notification settles a BANK_TRANSFER transaction. The transaction type
     * is authoritative — a provider callback can only reference its own
     * platform reference.
     */
    private function flowFor(string $reference): string
    {
        $transaction = Transaction::where('reference', $reference)->first();

        return $transaction !== null && $transaction->type === TransactionType::BankTransfer->value
            ? 'payout'
            : 'payment';
    }
}
