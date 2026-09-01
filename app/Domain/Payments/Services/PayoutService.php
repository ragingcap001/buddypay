<?php

namespace App\Domain\Payments\Services;

use App\Application\Commands\InitiateBankTransfer;
use App\Domain\Ledger\Constants\SystemAccounts;
use App\Domain\Notifications\Services\OutboxService;
use App\Domain\Payments\DTOs\PayoutRequest;
use App\Domain\Providers\Enums\ProviderOutcome;
use App\Domain\Providers\Services\ProviderGateway;
use App\Domain\Risk\Services\RiskEngine;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Enums\TransactionType;
use App\Domain\Transactions\Services\IdempotencyService;
use App\Domain\Transactions\Services\TransactionService;
use App\Domain\Transactions\Support\ReferenceGenerator;
use App\Domain\Wallet\Services\WalletService;
use App\Exceptions\FinancialException;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletReservation;
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates a wallet -> bank payout end to end:
 *
 *   1. idempotency begin (replays return the original result)
 *   2. risk assessment (KYC tier limits)
 *   3. ATOMIC initiation: wallet reservation + transaction + outbox event
 *   4. provider payout (outside the DB transaction — never hold locks while
 *      waiting on an external provider)
 *   5. ATOMIC outcome application:
 *        - DEFINITIVE_SUCCESS: commit reservation (DR WALLET / CR
 *          PROVIDER_PAYABLE + fee), COMPLETED
 *        - DEFINITIVE_FAILURE: release reservation, FAILED
 *        - AMBIGUOUS:          hold reservation, VERIFYING — the money stays
 *          reserved until the provider confirms (webhook, /verify endpoint
 *          or the stale-transaction sweeper). If the reservation TTL lapses
 *          first, the expirer releases the funds back to the user and the
 *          transaction is flagged by reconciliation.
 *   6. idempotency complete
 */
final class PayoutService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly TransactionService $transactions,
        private readonly IdempotencyService $idempotency,
        private readonly RiskEngine $risk,
        private readonly ProviderGateway $gateway,
        private readonly OutboxService $outbox,
    ) {
    }

    public function execute(InitiateBankTransfer $command): Transaction
    {
        $user = User::findOrFail($command->userId);

        $requestHash = $this->idempotency->hashRequest('POST', 'wallet/payout', [
            'amount' => $command->amountKobo,
            'bank_code' => $command->bankCode,
            'account_number' => $command->accountNumber,
            'account_name' => $command->accountName,
        ], $user->id);

        $begun = $this->idempotency->begin($user->id, $command->idempotencyKey, $requestHash);

        if ($begun['storedResponse'] !== null) {
            return $this->transactions->findByReference((string) $begun['idempotency']->transaction_reference);
        }

        $idempotencyKey = $begun['idempotency'];

        $fee = $this->transactions->calculateFee(TransactionType::BankTransfer, $command->amountKobo);
        $total = $command->amountKobo + $fee;

        // Risk: blocks (with audit) before any funds are reserved.
        $this->risk->assess($user, TransactionType::BankTransfer, $total);

        $providerName = $this->resolveProvider($command->provider);

        // --- Atomic initiation -------------------------------------------
        $transaction = DB::transaction(function () use ($user, $command, $fee, $total, $providerName): Transaction {
            $wallet = $this->wallets->forUser($user->id);

            // Payouts get a long-lived reservation (NIP can take hours).
            $reservation = $this->wallets->reserve(
                $wallet,
                $total,
                null,
                now()->addHours((int) config('ase.wallet.payout_reservation_ttl_hours', 24)),
            );

            $txn = $this->transactions->create([
                'reference' => ReferenceGenerator::transaction(),
                'user_id' => $user->id,
                'type' => TransactionType::BankTransfer->value,
                'status' => TransactionStatus::Initiated->value,
                'amount' => $command->amountKobo,
                'fee' => $fee,
                'currency' => config('ase.base_currency', 'NGN'),
                'metadata' => $command->metadata + [
                    'bank_code' => $command->bankCode,
                    'account_number' => $command->accountNumber,
                    'account_name' => $command->accountName,
                    'narration' => $command->narration,
                    'provider' => $providerName,
                ],
                'reservation_id' => $reservation->id,
            ]);

            $this->transactions->transition($txn, TransactionStatus::Pending, 'transaction created');
            $this->transactions->transition($txn, TransactionStatus::Processing, 'provider dispatch');

            $this->outbox->record('transaction', $txn->id, 'transaction.processing', [
                'reference' => $txn->reference,
                'type' => TransactionType::BankTransfer->value,
                'amount' => $command->amountKobo,
            ]);

            return $txn;
        });

        // --- Provider payout (outside the DB transaction) -----------------
        $response = $this->gateway->payout(
            $providerName,
            new PayoutRequest(
                $providerName,
                $transaction->amount,
                $command->bankCode,
                $command->accountNumber,
                $command->accountName,
                $transaction->reference,
                $command->narration,
            ),
            $transaction,
        );

        // --- Atomic outcome application -----------------------------------
        $this->applyOutcome(
            $transaction->reference,
            $providerName,
            (int) $transaction->amount,
            (int) $transaction->fee,
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
     * Verify an ambiguous/verifying payout against the provider. Safe to
     * call repeatedly; idempotent on the provider side.
     */
    public function verifyReference(Transaction $txn): Transaction
    {
        return DB::transaction(function () use ($txn): Transaction {
            $status = TransactionStatus::from($txn->status);

            if (! in_array($status, [TransactionStatus::Verifying, TransactionStatus::Ambiguous], true)) {
                throw new FinancialException(
                    'NOT_VERIFIABLE',
                    "Transaction [{$txn->reference}] is already settled (status: {$status->value}).",
                    409,
                );
            }

            if ($status === TransactionStatus::Ambiguous) {
                $this->transactions->transition($txn, TransactionStatus::Verifying, 'verification started');
            }

            if ($txn->provider_reference === null) {
                // Nothing to verify against yet — remain in VERIFYING.
                return $txn->fresh();
            }

            $providerName = (string) ($txn->provider ?? config('ase.default_payout_provider', 'wema'));

            $response = $this->gateway->verifyPayout($providerName, $txn->provider_reference);

            $this->applyOutcome(
                $txn->reference,
                $providerName,
                (int) $txn->amount,
                (int) $txn->fee,
                $response->outcome,
                $response->providerReference,
                $response->errorMessage,
            );

            return $txn->fresh();
        });
    }

    /**
     * Apply a provider outcome to a payout transaction. Called inside a
     * database transaction. Used by execute(), verifyReference() and
     * provider webhooks.
     */
    public function applyOutcome(
        string $transactionReference,
        string $providerName,
        int $amountKobo,
        int $fee,
        ProviderOutcome $outcome,
        ?string $providerReference,
        ?string $error,
    ): void {
        DB::transaction(function () use ($transactionReference, $providerName, $amountKobo, $fee, $outcome, $providerReference, $error): void {
            $txn = $this->transactions->findByReference($transactionReference);
            $reservation = $txn->reservation_id !== null ? WalletReservation::find($txn->reservation_id) : null;
            $reservationActive = $reservation !== null && $reservation->status === \App\Domain\Wallet\Enums\WalletReservationStatus::Active->value;

            if ($outcome === ProviderOutcome::DefinitiveSuccess) {
                if (! $reservationActive) {
                    // The reservation lapsed (TTL) before the provider
                    // confirmed. The external payout may have completed from
                    // the provider float — we cannot book it against a
                    // released reservation. Fail the transaction LOUDLY
                    // (never mark it COMPLETED without ledger entries) and
                    // flag it for reconciliation.
                    \Illuminate\Support\Facades\Log::critical(
                        'Payout confirmed after its reservation lapsed — reconciliation required',
                        [
                            'reference' => $txn->reference,
                            'provider' => $providerName,
                            'provider_reference' => $providerReference,
                            'amount_kobo' => $amountKobo,
                        ],
                    );

                    $this->transactions->transition($txn, TransactionStatus::Failed, 'reservation lapsed before provider confirmation (reconcile)');

                    $this->outbox->record('transaction', $txn->id, 'transaction.failed', [
                        'reference' => $txn->reference,
                        'type' => TransactionType::BankTransfer->value,
                        'error' => 'reservation lapsed before provider confirmation',
                    ]);

                    return;
                }

                // DR customer wallet (amount + fee)
                // CR provider payable (amount)
                // CR fee revenue (fee)
                $this->wallets->commit(
                    $reservation,
                    $amountKobo,
                    SystemAccounts::PROVIDER_PAYABLE,
                    $fee,
                );

                $txn->update([
                    'provider' => $providerName,
                    'provider_reference' => $providerReference ?? $txn->provider_reference,
                ]);

                $this->transactions->transition($txn, TransactionStatus::Success, 'payout confirmed', [
                    'provider_reference' => $providerReference,
                ]);
                $this->transactions->transition($txn, TransactionStatus::Completed, 'settled');

                $this->outbox->record('transaction', $txn->id, 'transaction.completed', [
                    'reference' => $txn->reference,
                    'type' => TransactionType::BankTransfer->value,
                    'amount' => $amountKobo,
                ]);
            } elseif ($outcome === ProviderOutcome::DefinitiveFailure) {
                if ($reservationActive) {
                    $this->wallets->release($reservation, 'payout_confirmed_failure');
                }

                $this->transactions->transition($txn, TransactionStatus::Failed, $error ?? 'payout failed');

                $this->outbox->record('transaction', $txn->id, 'transaction.failed', [
                    'reference' => $txn->reference,
                    'type' => TransactionType::BankTransfer->value,
                    'error' => $error,
                ]);
            } else {
                // Keep the provider reference even while ambiguous — /verify
                // has nothing to ask the provider about without it.
                $txn->update(['provider_reference' => $providerReference ?? $txn->provider_reference]);

                $status = TransactionStatus::from($txn->status);

                if ($status !== TransactionStatus::Verifying) {
                    $this->transactions->transition($txn, TransactionStatus::Ambiguous, $error ?? 'payout outcome unknown');
                    $this->transactions->transition($txn, TransactionStatus::Verifying, 'verification scheduled');
                }

                $this->outbox->record('transaction', $txn->id, 'transaction.ambiguous', [
                    'reference' => $txn->reference,
                    'type' => TransactionType::BankTransfer->value,
                ]);
            }
        });
    }

    private function resolveProvider(?string $requested): string
    {
        if ($requested !== null && $requested !== '') {
            return $requested;
        }

        return (string) config('ase.default_payout_provider', 'wema');
    }
}
