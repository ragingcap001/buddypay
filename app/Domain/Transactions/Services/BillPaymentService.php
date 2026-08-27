<?php

namespace App\Domain\Transactions\Services;

use App\Application\Commands\InitiateBillPayment;
use App\Domain\Ledger\Constants\SystemAccounts;
use App\Domain\Notifications\Services\OutboxService;
use App\Domain\Providers\Enums\ProviderOutcome;
use App\Domain\Providers\Services\ProviderGateway;
use App\Domain\Risk\Services\RiskEngine;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Enums\TransactionType;
use App\Domain\Transactions\Support\ReferenceGenerator;
use App\Domain\Wallet\Services\WalletService;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletReservation;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates a wallet-funded bill payment end to end:
 *
 *   1. idempotency begin (replays return the original result)
 *   2. risk assessment (KYC tier limits)
 *   3. ATOMIC initiation: wallet reservation + transaction + outbox event
 *   4. provider call (outside the DB transaction — never hold locks while
 *      waiting on an external provider)
 *   5. ATOMIC outcome application:
 *        - DEFINITIVE_SUCCESS: commit reservation, post ledger, COMPLETED
 *        - DEFINITIVE_FAILURE: release reservation, FAILED
 *        - AMBIGUOUS:          hold reservation, VERIFYING (verify, don't guess)
 *   6. idempotency complete
 */
final class BillPaymentService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly TransactionService $transactions,
        private readonly IdempotencyService $idempotency,
        private readonly RiskEngine $risk,
        private readonly ProviderGateway $gateway,
        private readonly OutboxService $outbox,
        private readonly \App\Domain\Bills\Services\BillCatalogService $catalog,
    ) {
    }

    public function execute(InitiateBillPayment $command): Transaction
    {
        $user = User::findOrFail($command->userId);

        $requestHash = $this->idempotency->hashRequest('POST', 'bills/pay', [
            'type' => $command->type->value,
            'amount' => $command->amountKobo,
            'phone' => $command->phoneNumber,
        ], $user->id);

        $begun = $this->idempotency->begin($user->id, $command->idempotencyKey, $requestHash);

        if ($begun['storedResponse'] !== null) {
            return $this->transactions->findByReference((string) $begun['idempotency']->transaction_reference);
        }

        $idempotencyKey = $begun['idempotency'];

        $fee = $this->transactions->calculateFee($command->type, $command->amountKobo);
        $total = $command->amountKobo + $fee;

        // Risk: blocks (with audit) before any funds are reserved.
        $this->risk->assess($user, $command->type, $total);

        // --- Atomic initiation -------------------------------------------
        $transaction = DB::transaction(function () use ($user, $command, $fee, $total): Transaction {
            $wallet = $this->wallets->forUser($user->id);
            $reservation = $this->wallets->reserve($wallet, $total);

            $txn = $this->transactions->create([
                'reference' => ReferenceGenerator::transaction(),
                'user_id' => $user->id,
                'type' => $command->type->value,
                'status' => TransactionStatus::Initiated->value,
                'amount' => $command->amountKobo,
                'fee' => $fee,
                'currency' => config('ase.base_currency', 'NGN'),
                'metadata' => $command->metadata + ['phone_number' => $command->phoneNumber],
                'reservation_id' => $reservation->id,
            ]);

            $this->transactions->transition($txn, TransactionStatus::Pending, 'transaction created');
            $this->transactions->transition($txn, TransactionStatus::Processing, 'provider dispatch');

            $this->outbox->record('transaction', $txn->id, 'transaction.processing', [
                'reference' => $txn->reference,
                'type' => $command->type->value,
                'amount' => $command->amountKobo,
            ]);

            return $txn;
        });

        // --- Provider call (outside the DB transaction) -------------------
        $providerName = $this->resolveProvider($command->type, $command->provider);

        $response = $this->gateway->purchaseBill(
            $providerName,
            $command->type,
            $command->phoneNumber,
            $command->amountKobo,
            $transaction->reference,
            $transaction,
            $command->metadata,
        );

        // Record the provider that took the purchase (the verification
        // path must always ask the right provider) and persist any
        // provider follow-up data (e.g. a short provider requestRef
        // needed for webhooks/status queries) on the metadata.
        $transaction->update([
            'provider' => $providerName,
            'metadata' => $transaction->metadata + $response->providerMetadata,
        ]);

        // --- Atomic outcome application -----------------------------------
        $this->applyOutcome(
            $transaction->reference,
            $command,
            $providerName,
            $response->outcome,
            $response->providerReference,
            $response->errorMessage,
        );

        $fresh = $transaction->fresh();

        $this->idempotency->complete($idempotencyKey, [
            'reference' => $fresh->reference,
            'status' => $fresh->status,
        ], $fresh->reference);

        return $fresh;
    }

    /**
     * Verify an ambiguous/verifying transaction against the provider.
     * Safe to call repeatedly — idempotent on the provider side.
     */
    public function verify(string $transactionReference): Transaction
    {
        return DB::transaction(function () use ($transactionReference): Transaction {
            $txn = $this->transactions->findByReference($transactionReference);
            $status = TransactionStatus::from($txn->status);

            if (! in_array($status, [TransactionStatus::Verifying, TransactionStatus::Ambiguous], true)) {
                throw new \App\Exceptions\FinancialException(
                    'NOT_VERIFIABLE',
                    "Transaction [{$transactionReference}] is already settled (status: {$status->value}).",
                    409,
                );
            }

            $providerName = (string) ($txn->provider ?? config('ase.default_bill_provider', 'mock'));

            $verification = $this->gateway->verifyBill(new \App\Domain\Providers\DTOs\BillVerificationRequest(
                $providerName,
                TransactionType::from($txn->type),
                $txn->reference,
                $txn->provider_reference,
            ));

            $this->applyVerification($txn, $verification->outcome, $verification->providerReference);

            return $txn->fresh();
        });
    }

    private function applyVerification(Transaction $txn, ProviderOutcome $outcome, ?string $providerReference): void
    {
        $status = TransactionStatus::from($txn->status);

        if ($status === TransactionStatus::Ambiguous) {
            $this->transactions->transition($txn, TransactionStatus::Verifying, 'verification started');
        }

        $reservation = $txn->reservation_id !== null ? WalletReservation::find($txn->reservation_id) : null;
        $reservationActive = $reservation !== null && $reservation->status === \App\Domain\Wallet\Enums\WalletReservationStatus::Active->value;

        if ($outcome === ProviderOutcome::DefinitiveSuccess) {
            if (! $reservationActive) {
                // Same guard as applyOutcome: never book a COMPLETED
                // purchase against a released reservation.
                \Illuminate\Support\Facades\Log::critical(
                    'Bill purchase confirmed after its reservation lapsed — reconciliation required',
                    ['reference' => $txn->reference, 'provider_reference' => $providerReference],
                );

                $this->transactions->transition($txn, TransactionStatus::Failed, 'reservation lapsed before provider confirmation (reconcile)');

                $this->outbox->record('transaction', $txn->id, 'transaction.failed', [
                    'reference' => $txn->reference,
                    'error' => 'reservation lapsed before provider confirmation',
                ]);

                return;
            }

            $this->wallets->commit(
                $reservation,
                (int) $txn->amount,
                SystemAccounts::PROVIDER_PAYABLE,
                (int) $txn->fee,
            );

            $txn->update(['provider_reference' => $providerReference ?? $txn->provider_reference]);
            $this->transactions->transition($txn, TransactionStatus::Success, 'verification confirmed success', ['provider_reference' => $providerReference]);
            $this->transactions->transition($txn, TransactionStatus::Completed, 'settled');
            $this->outbox->record('transaction', $txn->id, 'transaction.completed', [
                'reference' => $txn->reference,
            ]);
        } elseif ($outcome === ProviderOutcome::DefinitiveFailure) {
            if ($reservation !== null && $reservation->status === \App\Domain\Wallet\Enums\WalletReservationStatus::Active->value) {
                $this->wallets->release($reservation, 'verification_confirmed_failure');
            }
            $this->transactions->transition($txn, TransactionStatus::Failed, 'verification confirmed failure');
            $this->outbox->record('transaction', $txn->id, 'transaction.failed', [
                'reference' => $txn->reference,
            ]);
        } else {
            // Still ambiguous — remain in VERIFYING for the next attempt.
            $this->outbox->record('transaction', $txn->id, 'transaction.verification_pending', [
                'reference' => $txn->reference,
            ]);
        }
    }

    private function applyOutcome(
        string $transactionReference,
        InitiateBillPayment $command,
        string $providerName,
        ProviderOutcome $outcome,
        ?string $providerReference,
        ?string $error,
    ): void {
        DB::transaction(function () use ($transactionReference, $command, $providerName, $outcome, $providerReference, $error): void {
            $txn = $this->transactions->findByReference($transactionReference);
            $reservation = $txn->reservation_id !== null ? WalletReservation::find($txn->reservation_id) : null;
            $reservationActive = $reservation !== null && $reservation->status === \App\Domain\Wallet\Enums\WalletReservationStatus::Active->value;

            if ($outcome === ProviderOutcome::DefinitiveSuccess) {
                if (! $reservationActive) {
                    // The reservation lapsed (TTL) before the provider
                    // confirmed. We cannot book the purchase against a
                    // released reservation — fail LOUDLY (never a silent
                    // COMPLETED without ledger entries) and flag it for
                    // reconciliation.
                    \Illuminate\Support\Facades\Log::critical(
                        'Bill purchase confirmed after its reservation lapsed — reconciliation required',
                        [
                            'reference' => $txn->reference,
                            'provider' => $providerName,
                            'provider_reference' => $providerReference,
                        ],
                    );

                    $this->transactions->transition($txn, TransactionStatus::Failed, 'reservation lapsed before provider confirmation (reconcile)');

                    $this->outbox->record('transaction', $txn->id, 'transaction.failed', [
                        'reference' => $txn->reference,
                        'type' => $command->type->value,
                        'error' => 'reservation lapsed before provider confirmation',
                    ]);

                    return;
                }

                $this->wallets->commit(
                    $reservation,
                    $command->amountKobo,
                    SystemAccounts::PROVIDER_PAYABLE,
                    $this->transactions->calculateFee($command->type, $command->amountKobo),
                );

                $txn->update([
                    'provider' => $providerName,
                    'provider_reference' => $providerReference,
                ]);

                $this->transactions->transition($txn, TransactionStatus::Success, 'provider confirmed success', [
                    'provider_reference' => $providerReference,
                ]);
                $this->transactions->transition($txn, TransactionStatus::Completed, 'settled');

                $this->outbox->record('transaction', $txn->id, 'transaction.completed', [
                    'reference' => $txn->reference,
                    'type' => $command->type->value,
                    'amount' => $command->amountKobo,
                    'provider' => $providerName,
                ]);
            } elseif ($outcome === ProviderOutcome::DefinitiveFailure) {
                if ($reservationActive) {
                    $this->wallets->release($reservation, 'provider_failure');
                }

                $txn->update(['provider' => $providerName]);

                $this->transactions->transition($txn, TransactionStatus::Failed, $error ?? 'provider confirmed failure');

                $this->outbox->record('transaction', $txn->id, 'transaction.failed', [
                    'reference' => $txn->reference,
                    'type' => $command->type->value,
                    'error' => $error,
                ]);
            } else {
                // AMBIGUOUS: the reservation stays ACTIVE (funds remain held)
                // and the transaction moves to VERIFYING. No failover — the
                // original provider transaction must be verified first.
                $txn->update(['provider' => $providerName]);

                $this->transactions->transition($txn, TransactionStatus::Ambiguous, $error ?? 'provider outcome unknown');
                $this->transactions->transition($txn, TransactionStatus::Verifying, 'verification scheduled');

                $this->outbox->record('transaction', $txn->id, 'transaction.ambiguous', [
                    'reference' => $txn->reference,
                    'type' => $command->type->value,
                ]);
            }
        });
    }

    private function resolveProvider(TransactionType $type, ?string $override = null): string
    {
        if ($override !== null && $override !== '') {
            // Explicit provider selection (e.g. "kuda" for bill rails) —
            // must be a registered bill provider implementation.
            if (config("ase.providers.{$override}") === null) {
                throw new \App\Exceptions\FinancialException(
                    'PROVIDER_NOT_FOUND',
                    "No bill provider implementation registered for [{$override}].",
                    422,
                );
            }

            return $override;
        }

        return $this->catalog->resolveProvider($type);
    }
}
