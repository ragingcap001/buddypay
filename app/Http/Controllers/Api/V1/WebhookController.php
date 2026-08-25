<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Payments\Services\FundingService;
use App\Domain\Providers\Enums\ProviderOutcome;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Enums\TransactionType;
use App\Http\Controllers\Controller;
use App\Http\Support\ApiResponse;
use App\Models\Provider;
use App\Models\ProviderWebhook;
use App\Models\Transaction;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Generic provider webhook receiver: POST /api/v1/webhooks/{provider}
 *
 * 1. Authenticate the provider (HMAC signature of the raw body).
 * 2. Validate + store the raw event.
 * 3. Enforce idempotency (provider + event id is unique).
 * 4. Update transaction state idempotently.
 * 5. Record an audit trail.
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly FundingService $funding,
        private readonly AuditService $audit,
    ) {
    }

    public function handle(Request $request, string $providerName): JsonResponse
    {
        $provider = Provider::where('name', $providerName)->first();

        if ($provider === null) {
            return ApiResponse::error('PROVIDER_NOT_FOUND', "Provider [{$providerName}] is not registered.", 404, $request);
        }

        $secret = (string) config("ase.webhook_secrets.{$providerName}", '');
        $signature = (string) $request->header('X-Webhook-Signature', '');
        $expected = hash_hmac('sha256', $request->getContent(), $secret);

        if ($secret === '' || ! hash_equals($expected, $signature)) {
            return ApiResponse::error('WEBHOOK_SIGNATURE_INVALID', 'Invalid webhook signature.', 401, $request);
        }

        $payload = (array) $request->json()->all();
        $eventType = (string) ($payload['event_type'] ?? 'unknown');
        $eventId = (string) ($payload['event_id'] ?? (string) Str::ulid());

        // Idempotency: repeated delivery must not duplicate financial effects.
        $existing = ProviderWebhook::where('provider_id', $provider->id)
            ->where('provider_event_id', $eventId)
            ->first();

        if ($existing !== null) {
            return ApiResponse::success([
                'duplicate' => true,
                'status' => $existing->status,
            ], 'Webhook event already received');
        }

        $webhook = ProviderWebhook::create([
            'provider_id' => $provider->id,
            'event_type' => $eventType,
            'provider_event_id' => $eventId,
            'raw_payload' => $payload,
            'status' => ProviderWebhook::STATUS_RECEIVED,
        ]);

        try {
            $this->processEvent($providerName, $eventType, $payload);

            $webhook->update([
                'status' => ProviderWebhook::STATUS_PROCESSED,
                'processed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $webhook->update([
                'status' => ProviderWebhook::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);

            Log::error("Webhook [{$providerName}] event [{$eventId}] failed: {$e->getMessage()}");

            return ApiResponse::error('WEBHOOK_PROCESSING_FAILED', 'Webhook received but processing failed.', 500, $request);
        }

        $this->audit->log('webhook.received', $webhook, null, [
            'provider' => $providerName,
            'event_type' => $eventType,
            'event_id' => $eventId,
        ]);

        return ApiResponse::success(['status' => 'PROCESSED'], 'Webhook event processed');
    }

    private function processEvent(string $providerName, string $eventType, array $payload): void
    {
        $reference = (string) ($payload['reference'] ?? '');

        if ($reference === '') {
            return;
        }

        $txn = Transaction::where('reference', $reference)->first();

        if ($txn === null) {
            return; // No internal record — nothing to do (reconciliation will flag it).
        }

        if (TransactionStatus::from($txn->status)->isTerminal()) {
            return; // Already settled — idempotent no-op.
        }

        // This scaffold processes funding provider webhooks; bill provider
        // events are verified via POST /transactions/{reference}/verify.
        if ($txn->type !== TransactionType::WalletFunding->value) {
            return;
        }

        if ($eventType === 'payment.success') {
            $this->funding->applyOutcome(
                $txn->reference,
                $providerName,
                (int) $txn->amount,
                (int) $txn->fee,
                ProviderOutcome::DefinitiveSuccess,
                (string) ($payload['provider_reference'] ?? ''),
                null,
            );
        } elseif ($eventType === 'payment.failed') {
            $this->funding->applyOutcome(
                $txn->reference,
                $providerName,
                (int) $txn->amount,
                (int) $txn->fee,
                ProviderOutcome::DefinitiveFailure,
                null,
                (string) ($payload['error'] ?? 'webhook reported failure'),
            );
        }
    }
}
