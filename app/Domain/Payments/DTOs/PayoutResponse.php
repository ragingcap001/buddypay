<?php

namespace App\Domain\Payments\DTOs;

use App\Domain\Providers\Enums\ProviderOutcome;

/**
 * Result of a payout initiation.
 *
 * `providerReference` is the provider's own transaction reference (when
 * available) — use it with `verify()`, never the platform reference.
 */
final class PayoutResponse
{
    public function __construct(
        public readonly ProviderOutcome $outcome,
        public readonly ?string $providerReference = null,
        public readonly ?string $errorMessage = null,
    ) {
    }
}
