<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Audit\Services\AuditService;
use App\Domain\Payments\Services\FundingService;
use App\Domain\Payments\Services\PayoutService;
use App\Domain\Providers\DTOs\NormalizedWebhookEvent;
use App\Domain\Providers\Enums\ProviderOutcome;
use App\Domain\Providers\Services\ProviderWebhookNormalizer;
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
use Throwable;

/**
 * Generic provider webhook receiver: POST /api/v1/webhooks/{provider}
 *
 * 1. Authenticate the provider:
 *      - mock / wema / ...: HMAC-SHA256 of the raw body in
 *        `X-Webhook-Signature`, keyed by the provider's webhook secret.
 *      - monnify: HMAC-SHA512 of the raw body in `monnify-signature`, keyed
 *        by the Monnify client SECRET key (production only — sandbox
 *        notifications are unsigned, so no secret should be configured
 *        when pointing the Monnify dashboard at a sandbox receiver).
 * 2. Normalize the raw event (provider-specific shapes → one vocabulary).
 * 3. Enforce idempotency (provider + event id is unique).
 * 4. Update transaction state idempotently.
 * 5. Record an audit trail.
 */
class WebhookController extends Controller
{
    public function __construct(
        private readonly FundingService $funding,
        private readonly PayoutService $payouts,
        private readonly ProviderWebhookNormalizer $normalizer,
        private readonly AuditService $audit,
        private readonly \App\Domain\Config\Services\AppConfigService $appConfig,
    ) {
    }

    public function handle(Request $request, string $providerName): JsonResponse
    {
        $provider = Provider::where('name', $providerName)->first();

        if ($provider === null) {
            return ApiResponse::error('PROVIDER_NOT_FOUND', "Provider [{$providerName}] is not registered.", 404, $request);
        }

        if (! $this->signatureIsValid($providerName, $request)) {
            return ApiResponse::error('WEBHOOK_SIGNATURE_INVALID', 'Invalid webhook signature.', 401, $request);
        }

        $payload = (array) $request->json()->all();
        $event = $this->normalizer->normalize($providerName, $payload);

        // Idempotency: repeated delivery must not duplicate financial effects.
        $existing = ProviderWebhook::where('provider_id', $provider->id)
            ->where('provider_event_id', $event->eventId)
            ->first();

        if ($existing !== null) {
            return ApiResponse::success([
                'duplicate' => true,
                'status' => $existing->status,
            ], 'Webhook event already received');
        }

        $webhook = ProviderWebhook::create([
            'provider_id' => $provider->id,
            'event_type' => $event->eventType,
            'provider_event_id' => $event->eventId,
            'raw_payload' => $payload,
            'status' => ProviderWebhook::STATUS_RECEIVED,
        ]);

        // Events that carry no definitive outcome (e.g. a PENDING callback)
        // are stored for the audit trail and acknowledged, then ignored.
        if ($event->eventType === NormalizedWebhookEvent::IGNORED || $event->reference === '') {
            $webhook->update([
                'status' => ProviderWebhook::STATUS_PROCESSED,
                'processed_at' => now(),
            ]);

            return ApiResponse::success(['status' => 'RECEIVED'], 'Webhook event received');
        }

        try {
            $this->processEvent($providerName, $event);

            $webhook->update([
                'status' => ProviderWebhook::STATUS_PROCESSED,
                'processed_at' => now(),
            ]);
        } catch (Throwable $e) {
            $webhook->update([
                'status' => ProviderWebhook::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);

            Log::error("Webhook [{$providerName}] event [{$event->eventId}] failed: {$e->getMessage()}");

            return ApiResponse::error('WEBHOOK_PROCESSING_FAILED', 'Webhook received but processing failed.', 500, $request);
        }

        $this->audit->log('webhook.received', $webhook, null, [
            'provider' => $providerName,
            'event_type' => $event->eventType,
            'event_id' => $event->eventId,
            'reference' => $event->reference,
        ]);

        return ApiResponse::success(['status' => 'PROCESSED'], 'Webhook event processed');
    }

    /**
     * Verify the raw-body signature. The signature MUST be computed over the
     * raw body bytes — never over the re-encoded JSON.
     */
    private function signatureIsValid(string $providerName, Request $request): bool
    {
        $rawBody = $request->getContent();

        if ($providerName === 'monnify') {
            // Monnify signs webhooks with the client secret (HMAC-SHA512).
            // Admin-dashboard override first, MONNIFY_SECRET_KEY as fallback.
            $secret = (string) ($this->appConfig->get('monnify', 'secret_key') ?? '');
            $signature = (string) $request->header('monnify-signature', $request->header('X-Monnify-Signature', ''));

            if ($secret === '' || $signature === '') {
                return false;
            }

            return hash_equals(hash_hmac('sha512', $rawBody, $secret), $signature);
        }

        if ($providerName === 'wema') {
            $secret = (string) ($this->appConfig->get('wema', 'webhook_secret') ?? '');
        } else {
            $secret = (string) config("ase.webhook_secrets.{$providerName}", '');
        }
        $signature = (string) $request->header('X-Webhook-Signature', '');

        if ($secret === '' || $signature === '') {
            return false;
        }

        return hash_equals(hash_hmac('sha256', $rawBody, $secret), $signature);
    }

    private function processEvent(string $providerName, NormalizedWebhookEvent $event): void
    {
        $txn = Transaction::where('reference', $event->reference)->first();

        if ($txn === null) {
            return; // No internal record — nothing to do (reconciliation will flag it).
        }

        if (TransactionStatus::from($txn->status)->isTerminal()) {
            return; // Already settled — idempotent no-op.
        }

        $outcome = match ($event->eventType) {
            'payment.success', 'payout.success' => ProviderOutcome::DefinitiveSuccess,
            'payment.failed', 'payout.failed' => ProviderOutcome::DefinitiveFailure,
            default => null,
        };

        if ($outcome === null) {
            return;
        }

        // Bill provider events are verified via POST /transactions/{ref}/verify;
        // this receiver settles funding (deposits) and payout (transfers out)
        // transactions.
        if ($txn->type === TransactionType::WalletFunding->value) {
            $this->funding->applyOutcome(
                $txn->reference,
                $providerName,
                (int) $txn->amount,
                (int) $txn->fee,
                $outcome,
                $event->providerReference,
                $event->error,
            );
        } elseif ($txn->type === TransactionType::BankTransfer->value) {
            $this->payouts->applyOutcome(
                $txn->reference,
                $providerName,
                (int) $txn->amount,
                (int) $txn->fee,
                $outcome,
                $event->providerReference,
                $event->error,
            );
        }
    }
}
