<?php

namespace App\Domain\Risk\Services;

use App\Domain\KYC\Enums\KycTier;
use App\Domain\Risk\Enums\RiskOutcome;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Enums\TransactionType;
use App\Exceptions\RiskBlockedException;
use App\Models\RiskAssessment;
use App\Models\Transaction;
use App\Models\Wallet;
use App\Models\User;

/**
 * Rule-based risk engine.
 *
 * Evaluates configurable limits (per-transaction, daily amount, daily count,
 * wallet balance) against the user's KYC tier. Signals are recorded on the
 * assessment so reviewers can see why a transaction was blocked.
 */
final class RiskEngine
{
    /**
     * @return array{outcome: RiskOutcome, signals: array<int, string>}
     *
     * @throws RiskBlockedException when the outcome is BLOCK
     */
    public function assess(User $user, TransactionType $type, int $amountKobo, bool $increasesWalletBalance = false): array
    {
        $tier = KycTier::tryFrom((int) ($user->kyc_profile?->tier ?? 0)) ?? KycTier::Unverified;
        $limits = $tier->limits();

        $signals = [];

        if ($amountKobo > (int) $limits['per_transaction']) {
            $signals[] = 'per_transaction_limit_exceeded';
        }

        $openStatuses = [
            TransactionStatus::Pending->value,
            TransactionStatus::Processing->value,
            TransactionStatus::Ambiguous->value,
            TransactionStatus::Verifying->value,
            TransactionStatus::Success->value,
            TransactionStatus::Completed->value,
        ];

        $today = Transaction::where('user_id', $user->id)
            ->whereIn('status', $openStatuses)
            ->where('created_at', '>=', now()->startOfDay())
            ->get();

        $dailyTotal = (int) $today->sum(fn (Transaction $t): int => (int) $t->amount + (int) $t->fee);

        if ($dailyTotal + $amountKobo > (int) $limits['daily_amount']) {
            $signals[] = 'daily_amount_limit_exceeded';
        }

        if ($today->count() + 1 > (int) $limits['daily_count']) {
            $signals[] = 'daily_count_limit_exceeded';
        }

        if ($increasesWalletBalance) {
            $wallet = Wallet::where('user_id', $user->id)->first();

            if ($wallet !== null && (int) $wallet->control_balance + $amountKobo > (int) $limits['max_wallet_balance']) {
                $signals[] = 'wallet_balance_limit_exceeded';
            }
        }

        $outcome = $signals === [] ? RiskOutcome::Allow : RiskOutcome::Block;

        RiskAssessment::create([
            'user_id' => $user->id,
            'transaction_type' => $type->value,
            'amount' => $amountKobo,
            'kyc_tier' => $tier->value,
            'outcome' => $outcome->value,
            'signals' => $signals,
        ]);

        if ($outcome === RiskOutcome::Block) {
            throw new RiskBlockedException(implode(', ', $signals));
        }

        return ['outcome' => $outcome, 'signals' => $signals];
    }
}
