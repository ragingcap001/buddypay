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
            'kuda' => $this->normalizeKuda($payload),
            default => $this->normalizeGeneric($payload),
        };
    }

    /**
     * Kuda `Bill.Transaction` webhook.
     *
     * Kuda identifies bills by `BillRequestRef` (our short Kuda requestRef)
     * and `BillResponseReference` — NOT by the platform reference — and the
     * notification itself is not a definitive outcome (Kuda says to confirm
     * with BILL_TSQ). So we resolve the platform transaction from the
     * stored provider reference and emit a "verify" event that the receiver
     * settles through the bill verification path.
     */
    private function normalizeKuda(array $payload): NormalizedWebhookEvent
    {
        $eventType = (string) ($payload['eventType'] ?? '');

        if ($eventType !== 'Bill.Transaction') {
            // Transaction.Notification and anything else are out of scope
            // for bill settlement — store and ignore.
            return new NormalizedWebhookEvent(
                NormalizedWebhookEvent::IGNORED,
                (string) ($payload['transactionReference'] ?? ''),
                null,
                null,
                'kuda|'.((string) ($payload['eventType'] ?? 'unknown')).'|'.((string) ($payload['instrumentNumber'] ?? $payload['transactionReference'] ?? '')),
            );
        }

        $billRequestRef = (string) ($payload['BillRequestRef'] ?? '');
        $billResponseReference = (string) ($payload['BillResponseReference'] ?? '');

        // Join to the platform transaction: we store the Kuda bill response
        // reference as provider_reference and the short requestRef in
        // metadata.
        $txn = \App\Models\Transaction::where('provider_reference', $billResponseReference)->first();

        if ($txn === null && $billRequestRef !== '') {
            $txn = \App\Models\Transaction::where('metadata->kuda_request_ref', $billRequestRef)->first();
        }

        if ($txn === null) {
            // No internal record — reconciliation will flag it.
            return new NormalizedWebhookEvent(
                NormalizedWebhookEvent::IGNORED,
                '',
                $billResponseReference !== '' ? $billResponseReference : null,
                null,
                'kuda|Bill.Transaction|'.$billRequestRef.'|'.$billResponseReference,
            );
        }

        return new NormalizedWebhookEvent(
            eventType: 'bill.transaction',
            reference: (string) $txn->reference,
            providerReference: $billResponseReference !== '' ? $billResponseReference : null,
            error: null,
            eventId: 'kuda|Bill.Transaction|'.$billRequestRef.'|'.$billResponseReference,
        );
    }

    private function normalizeMonnify(array $payload): NormalizedWebhookEvent
    {
        $rawEventType = (string) ($payload['eventType'] ?? '');
        $data = (array) ($payload['eventData'] ?? []);

        $providerReference = (string) ($data['transactionReference'] ?? '');
        $isDisbursement = str_contains($rawEventType, 'DISBURSEMENT');

        $reference = $isDisbursement
            ? (string) ($data['reference'] ?? $providerReference)
            : (string) (($data['paymentReference'] ?? ($data['product']['reference'] ?? $providerReference)));

        $base = $isDisbursement ? 'payout' : 'payment';

        // Only known terminal event types may settle transactions. Anything
        // else (settlements, refunds, retries, future events) is stored for
        // the audit trail and ignored.
        $eventType = match ($rawEventType) {
            'SUCCESSFUL_TRANSACTION', 'SUCCESSFUL_DISBURSEMENT' => $base.'.success',
            'UNSUCCESSFUL_TRANSACTION', 'FAILED_DISBURSEMENT', 'UNSUCCESSFUL_DISBURSEMENT' => $base.'.failed',
            default => NormalizedWebhookEvent::IGNORED,
        };

        return new NormalizedWebhookEvent(
            eventType: $eventType,
            reference: $reference,
            providerReference: $providerReference !== '' ? $providerReference : null,
            error: $eventType === $base.'.failed'
                ? (string) ($data['paymentDescription'] ?? "Monnify reported a failed {$base}")
                : null,
            eventId: $rawEventType.'|'.($providerReference !== '' ? $providerReference : $reference),
            // Collections report amountPaid; disbursements report amount.
            amountKobo: $this->nairaToKobo($data['amountPaid'] ?? ($data['amount'] ?? null)),
        );
    }

    /**
     * Naira -> kobo. Accepts the shapes Monnify actually sends: "2500.00"
     * (string, 2dp), "2500" / 2500 (whole Naira), 2500.5 (float). Pure
     * integer math where possible; null when unrecognised (e.g. the Wema
     * callback carries no amount) — the amount guard is then skipped.
     */
    private function nairaToKobo(mixed $naira): ?int
    {
        if (is_int($naira)) {
            return $naira * 100;
        }

        if (is_float($naira)) {
            return (int) round($naira * 100);
        }

        if (! is_string($naira)) {
            return null;
        }

        if (preg_match('/^\d{1,13}\.\d{2}$/', $naira)) {
            [$major, $minor] = explode('.', $naira);

            return ((int) $major) * 100 + (int) $minor;
        }

        if (preg_match('/^\d{1,13}$/', $naira)) {
            return ((int) $naira) * 100;
        }

        return null;
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
