<?php

namespace App\Domain\Providers\DTOs;

/**
 * Provider-agnostic view of a webhook notification.
 *
 * `eventType` is one of:
 *   - payment.success / payment.failed  (wallet funding deposit settled)
 *   - payout.success / payout.failed    (bank transfer out settled)
 *   - ignored                           (e.g. a PENDING acknowledgement that
 *                                        carries no definitive outcome)
 *
 * `reference` is the platform transaction reference the event applies to;
 * `providerReference` is the provider's own reference (audit/reconciliation);
 * `eventId` is the idempotency key for the provider_webhooks table.
 */
final class NormalizedWebhookEvent
{
    public const IGNORED = 'ignored';

    public function __construct(
        public readonly string $eventType,
        public readonly string $reference,
        public readonly ?string $providerReference,
        public readonly ?string $error,
        public readonly string $eventId,
    ) {
    }
}
