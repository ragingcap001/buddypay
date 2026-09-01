<?php

namespace App\Domain\Payments\DTOs;

/**
 * Value object describing a wallet -> bank payout (bank transfer out).
 *
 * Amounts are integer kobo. `transactionReference` is the platform's
 * transaction reference and MUST be passed to the provider as the provider
 * transaction reference — it is the join key used by webhooks and
 * verification to settle the internal transaction.
 */
final class PayoutRequest
{
    public function __construct(
        public readonly string $providerName,
        public readonly int $amountKobo,
        public readonly string $bankCode,
        public readonly string $accountNumber,
        public readonly string $accountName,
        public readonly string $transactionReference,
        public readonly ?string $narration = null,
    ) {
    }
}
