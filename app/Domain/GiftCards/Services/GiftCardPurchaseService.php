<?php

namespace App\Domain\GiftCards\Services;

use App\Application\Commands\InitiateGiftCardPurchase;
use App\Domain\GiftCards\DTOs\GiftCardPurchaseRequest;
use App\Domain\Ledger\Constants\SystemAccounts;
use App\Domain\Notifications\Services\OutboxService;
use App\Domain\Providers\Enums\ProviderOutcome;
use App\Domain\Providers\Services\ProviderGateway;
use App\Domain\Risk\Services\RiskEngine;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Enums\TransactionType;
use App\Domain\Transactions\Services\IdempotencyService;
use App\Domain\Transactions\Services\TransactionService;
use App\Domain\Transactions\Support\ReferenceGenerator;
use App\Domain\Wallet\Services\WalletService;
use App\Infrastructure\Providers\ProviderFactory;
use App\Models\GiftCardRedemption;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletReservation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Same orchestration shape as BillPaymentService (idempotency -> risk ->
 * atomic reserve+create -> provider call outside the DB transaction ->
 * atomic outcome application) — gift cards get their own service rather
 * than being forced through BillPaymentService because the DTOs, the
 * redeem-code follow-up, and the pricing lookup don't fit the
 * bill-purchase shape.
 */
final class GiftCardPurchaseService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly TransactionService $transactions,
        private readonly IdempotencyService $idempotency,
        private readonly RiskEngine $risk,
        private readonly ProviderGateway $gateway,
        private readonly OutboxService $outbox,
        private readonly ProviderFactory $providerFactory,
    ) {
    }

    public function execute(InitiateGiftCardPurchase $command): Transaction
    {
        $user = User::findOrFail($command->userId);

        $requestHash = $this->idempotency->hashRequest('POST', 'giftcard/purchase', [
            'productId' => $command->productId,
            'denomination' => $command->denomination,
        ], $user->id);

        $begun = $this->idempotency->begin($user->id, $command->idempotencyKey, $requestHash);

        if ($begun['storedResponse'] !== null) {
            return $this->transactions->findByReference((string) $begun['idempotency']->transaction_reference);
        }

        $idempotencyKey = $begun['idempotency'];

        // Risk: blocks (with audit) before any funds are reserved.
        $this->risk->assess($user, TransactionType::GiftCard, $command->totalKobo);

        $transaction = DB::transaction(function () use ($user, $command): Transaction {
            $wallet = $this->wallets->forUser($user->id);
            $reservation = $this->wallets->reserve($wallet, $command->totalKobo);

            $wallet->refresh();
            $newBalance = $wallet->availableBalance();
            $oldBalance = $newBalance + $command->totalKobo;

            $txn = $this->transactions->create([
                'reference' => ReferenceGenerator::transaction(),
                'user_id' => $user->id,
                'type' => TransactionType::GiftCard->value,
                'status' => TransactionStatus::Initiated->value,
                'amount' => $command->totalKobo,
                'fee' => 0,
                'currency' => config('ase.base_currency', 'NGN'),
                'metadata' => $command->metadata + [
                    'old_balance' => $oldBalance,
                    'new_balance' => $newBalance,
                ],
                'reservation_id' => $reservation->id,
            ]);

            $this->transactions->transition($txn, TransactionStatus::Pending, 'transaction created');
            $this->transactions->transition($txn, TransactionStatus::Processing, 'provider dispatch');

            $this->outbox->record('transaction', $txn->id, 'transaction.processing', [
                'reference' => $txn->reference,
                'type' => TransactionType::GiftCard->value,
                'amount' => $command->totalKobo,
            ]);

            return $txn;
        });

        $providerName = (string) config('ase.default_giftcard_provider', 'reloadly');
        $senderName = trim("{$user->first_name} {$user->last_name}") ?: (string) $user->name;

        $response = $this->gateway->purchaseGiftCard($providerName, new GiftCardPurchaseRequest(
            providerName: $providerName,
            productId: $command->productId,
            unitPrice: $command->denomination,
            senderName: $senderName,
            transactionReference: $transaction->reference,
        ), $transaction);

        $transaction->update([
            'provider' => $providerName,
            // metadata is a nullable column; the array cast passes null
            // through untouched, so `+` would fatal if it were ever null.
            'metadata' => ($transaction->metadata ?? []) + $response->providerMetadata,
        ]);

        $redeemCodeTarget = $this->applyOutcome($transaction->reference, $providerName, $response->outcome, $response->providerReference, $response->errorMessage);

        if ($redeemCodeTarget !== null) {
            $this->storeRedeemCode($redeemCodeTarget['txn'], $providerName, $redeemCodeTarget['providerReference']);
        }

        $fresh = $transaction->fresh();

        $this->idempotency->complete($idempotencyKey, [
            'reference' => $fresh->reference,
            'status' => $fresh->status,
        ], $fresh->reference);

        return $fresh;
    }

    /**
     * Drive an AMBIGUOUS/VERIFYING gift card purchase to a definite
     * outcome by asking Reloadly directly (by our own transaction
     * reference, passed as `customIdentifier` on the original order) —
     * never a guess, never a failover.
     */
    public function verify(string $transactionReference): Transaction
    {
        // Deliberately NOT wrapped in its own DB::transaction() — that
        // would hold the row lock through both the network call to
        // Reloadly below and the redeem-code fetch applyOutcome() may
        // trigger. applyOutcome() provides its own correctly-scoped
        // transaction around the actual mutation; this method only needs
        // a plain (unlocked) read to decide whether verifying even makes
        // sense.
        $txn = $this->transactions->findByReference($transactionReference);
        $status = TransactionStatus::from($txn->status);

        if (! in_array($status, [TransactionStatus::Verifying, TransactionStatus::Ambiguous], true)) {
            throw new \App\Exceptions\FinancialException(
                'NOT_VERIFIABLE',
                "Transaction [{$transactionReference}] is already settled (status: {$status->value}).",
                409,
            );
        }

        $providerName = (string) ($txn->provider ?? config('ase.default_giftcard_provider', 'reloadly'));
        $verification = $this->gateway->verifyGiftCard($providerName, $txn->reference);

        if ($verification->providerMetadata !== []) {
            $txn->update(['metadata' => ($txn->metadata ?? []) + $verification->providerMetadata]);
        }

        $redeemCodeTarget = $this->applyOutcome($txn->reference, $providerName, $verification->outcome, $verification->providerReference, $verification->errorMessage, isVerification: true);

        if ($redeemCodeTarget !== null) {
            $this->storeRedeemCode($redeemCodeTarget['txn'], $providerName, $redeemCodeTarget['providerReference']);
        }

        return $txn->fresh();
    }

    /**
     * Applies the outcome inside its own DB transaction (row locks on the
     * wallet + transaction) and returns the redeem-code fetch target, if
     * any, for the CALLER to act on once that transaction has committed.
     * The fetch is a live HTTP call to Reloadly — running it here, inside
     * the lock, would stall every other operation on this user's wallet
     * for as long as Reloadly takes to respond, breaking the same "never
     * hold locks while waiting on an external provider" rule every other
     * provider call in this codebase already respects.
     *
     * @return array{txn: Transaction, providerReference: string}|null
     */
    private function applyOutcome(
        string $transactionReference,
        string $providerName,
        ProviderOutcome $outcome,
        ?string $providerReference,
        ?string $error,
        bool $isVerification = false,
    ): ?array {
        return DB::transaction(function () use ($transactionReference, $providerName, $outcome, $providerReference, $error, $isVerification): ?array {
            $txn = $this->transactions->findByReference($transactionReference);
            $status = TransactionStatus::from($txn->status);
            $reservation = $txn->reservation_id !== null ? WalletReservation::find($txn->reservation_id) : null;
            $reservationActive = $reservation !== null && $reservation->status === \App\Domain\Wallet\Enums\WalletReservationStatus::Active->value;

            if ($isVerification && $status === TransactionStatus::Ambiguous) {
                $this->transactions->transition($txn, TransactionStatus::Verifying, 'verification started');
            }

            if ($outcome === ProviderOutcome::DefinitiveSuccess) {
                if (! $reservationActive) {
                    Log::critical(
                        'Gift card order confirmed after its reservation lapsed — reconciliation required',
                        ['reference' => $txn->reference, 'provider' => $providerName, 'provider_reference' => $providerReference],
                    );

                    $this->transactions->transition($txn, TransactionStatus::Failed, 'reservation lapsed before provider confirmation (reconcile)');
                    $this->outbox->record('transaction', $txn->id, 'transaction.failed', [
                        'reference' => $txn->reference,
                        'error' => 'reservation lapsed before provider confirmation',
                    ]);

                    return null;
                }

                $this->wallets->commit($reservation, (int) $txn->amount, SystemAccounts::PROVIDER_PAYABLE);
                $this->checkMargin($txn);

                $txn->update(['provider' => $providerName, 'provider_reference' => $providerReference]);
                $this->transactions->transition($txn, TransactionStatus::Success, 'provider confirmed success', ['provider_reference' => $providerReference]);
                $this->transactions->transition($txn, TransactionStatus::Completed, 'settled');

                $this->outbox->record('transaction', $txn->id, 'transaction.completed', [
                    'reference' => $txn->reference,
                    'type' => TransactionType::GiftCard->value,
                    'provider' => $providerName,
                ]);

                return $providerReference !== null ? ['txn' => $txn->fresh(), 'providerReference' => $providerReference] : null;
            } elseif ($outcome === ProviderOutcome::DefinitiveFailure) {
                if ($reservationActive) {
                    $this->wallets->release($reservation, 'provider_failure');
                }

                $txn->update(['provider' => $providerName]);
                $this->transactions->transition($txn, TransactionStatus::Failed, $error ?? 'provider confirmed failure');

                $this->outbox->record('transaction', $txn->id, 'transaction.failed', [
                    'reference' => $txn->reference,
                    'type' => TransactionType::GiftCard->value,
                    'error' => $error,
                ]);

                return null;
            } elseif (! $isVerification) {
                // Initial purchase call, ambiguous outcome: PROCESSING -> AMBIGUOUS -> VERIFYING.
                $txn->update([
                    'provider' => $providerName,
                    'provider_reference' => $providerReference ?? $txn->provider_reference,
                ]);

                $this->transactions->transition($txn, TransactionStatus::Ambiguous, $error ?? 'provider outcome unknown');
                $this->transactions->transition($txn, TransactionStatus::Verifying, 'verification scheduled');

                $this->outbox->record('transaction', $txn->id, 'transaction.ambiguous', [
                    'reference' => $txn->reference,
                    'type' => TransactionType::GiftCard->value,
                ]);

                return null;
            } else {
                // Re-verification, still ambiguous: VERIFYING has no
                // AMBIGUOUS arm in the state machine (by design — it would
                // let a transaction bounce back out of the "being
                // verified" state). Stay in VERIFYING for the next pass.
                if ($providerReference !== null) {
                    $txn->update(['provider_reference' => $providerReference]);
                }

                $this->outbox->record('transaction', $txn->id, 'transaction.verification_pending', [
                    'reference' => $txn->reference,
                    'type' => TransactionType::GiftCard->value,
                ]);

                return null;
            }
        });
    }

    /**
     * Reloadly's actual charge (`reloadly_amount`, in providerMetadata)
     * is never compared against what we expected to pay
     * (`expected_base_ngn_kobo`, set at purchase time in
     * GiftCardCatalogService::priceForPurchase()) — unlike the webhook
     * amount guard Monnify/Wema funding already has. A silent mismatch
     * (Reloadly's fee changed, FX moved between quote and settlement)
     * would quietly eat margin forever. This doesn't block or reverse
     * the purchase — the charge already happened — it only makes drift
     * visible instead of invisible.
     */
    private function checkMargin(Transaction $txn): void
    {
        $metadata = (array) $txn->metadata;
        $expectedKobo = $metadata['expected_base_ngn_kobo'] ?? null;
        $actualNgn = $metadata['reloadly_amount'] ?? null;

        if ($expectedKobo === null || $actualNgn === null) {
            return; // Nothing to compare (e.g. re-verified without a fresh quote).
        }

        $actualKobo = (int) round(((float) $actualNgn) * 100);
        $driftKobo = $actualKobo - (int) $expectedKobo;

        if ($driftKobo !== 0) {
            Log::warning('Gift card margin drift — Reloadly charged a different amount than quoted', [
                'reference' => $txn->reference,
                'expected_base_ngn_kobo' => $expectedKobo,
                'actual_base_ngn_kobo' => $actualKobo,
                'drift_kobo' => $driftKobo,
            ]);
        }
    }

    /**
     * Read-only follow-up, not a money-movement call — fetched directly
     * from the provider rather than through ProviderGateway's
     * circuit-breaker/outcome-classification funnel, which exists for
     * calls that move money, not for retrieving a code after the fact.
     * A failure here never affects the (already committed) transaction —
     * the customer can be shown "processing" and the code back-filled by
     * a retry, unlike the reservation/ledger decision above.
     */
    private function storeRedeemCode(Transaction $txn, string $providerName, string $providerReference): void
    {
        try {
            $code = $this->providerFactory->makeGiftCardProvider($providerName)->redeemCode($providerReference);
        } catch (\Throwable $e) {
            Log::warning('Could not fetch gift card redeem code', ['reference' => $txn->reference, 'error' => $e->getMessage()]);

            return;
        }

        if ($code === null) {
            return;
        }

        GiftCardRedemption::updateOrCreate(
            ['transaction_id' => $txn->id],
            ['card_number' => $code->cardNumber, 'pin_code' => $code->pinCode, 'redemption_url' => $code->redemptionUrl],
        );
    }
}
