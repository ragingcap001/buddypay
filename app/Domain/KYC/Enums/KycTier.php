<?php

namespace App\Domain\KYC\Enums;

enum KycTier: int
{
    case Unverified = 0;
    case Basic = 1;
    case Full = 2;

    /**
     * Limits for this tier, in integer minor units (kobo).
     *
     * @return array{per_transaction: int, daily_amount: int, daily_count: int, max_wallet_balance: int}
     */
    public function limits(): array
    {
        $tiers = config('ase.kyc_tiers');

        return $tiers[$this->value] ?? $tiers[0];
    }
}
