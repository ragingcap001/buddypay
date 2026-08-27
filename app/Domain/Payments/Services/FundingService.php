<?php

namespace App\Domain\Payments\Services;

use App\Application\Commands\FundWallet;
use App\Domain\Ledger\Constants\SystemAccounts;
use App\Domain\Notifications\Services\OutboxService;
use App\Domain\Payments\DTOs\PaymentChargeRequest;
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
use Illuminate\Support\Facades\DB;

/**
 * Orchestrates wallet funding through a payment provider:
 *
 *   1. idempotency begin
 *   2. risk assessment (wallet balance limit by KYC tier)
 *   3. ATOMIC initiation: transaction + outbox (no reservation — funds are
 *      not taken from the wallet, they are added to it)
 *   4. provider charge (outside the DB transaction)
 *   5. ATOMIC outcome:
 *        - DEFINITIVE_SUCCESS: credit wallet + post ledger
 *          (DR FUNDING_RECEIVABLE / CR customer wallet), COMPLETED
 *        - DEFINITIVE_FAILURE: FAILED
 *        - AMBIGUOUS:          VERIFYING — the wallet is NOT credited until
 *          the outcome is verified (POST /transactions/{ref}/verify or a
 *          provider webhook).
 */
final class FundingService
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

    public function execute(FundWallet $command): Transaction
    {
        $user = User::findOrFail($command->userId);

        $requestHash = $this->idempotency->hashRequest('POST', 'wallet/fund', [
            'amount' => $command->amountKobo,
            'method' => $command->method,
        ], $user->id);

        $begun = $this->idempotency->begin($user->id, $command->idempotencyKey, $requestHash);

        if ($begun['storedResponse'] !== null) {
            return $this->transactions->findByReference((string) $begun['idempotency']->transaction_reference);
        }

        $idempotencyKey = $begun['idempotency'];

        $fee = $this->transactions->calculateFee(TransactionType::WalletFunding, $command->amountKobo);
        $total = $command->amountKobo + $fee;

        $this->risk->assess($user, TransactionType::WalletFunding, $total, increasesWalletBalance: true);

        // --- Atomic initiation --------------------------------------------
        $transaction = DB::transaction(function () use ($user, $command, $fee, $total): Transaction {
            $txn = $this->transactions->create([
                'reference' => ReferenceGenerator::transaction(),
                'user_id' => $user->id,
                'type' => TransactionType::WalletFunding->value,
                'status' => TransactionStatus::Initiated->value,
                'amount' => $command->amountKobo,
                'fee' => $fee,
                'currency' => config('ase.base_currency', 'NGN'),
                'metadata' => $command->metadata + ['method' => $command->method],
            ]);

            $this->transactions->transition($txn, TransactionStatus::Pending, 'funding requested');
            $this->transactions->transition($txn, TransactionStatus::Processing, 'provider dispatch');

            $this->outbox->record('transaction', $txn->id, 'transaction.processing', [
                'reference' => $txn->reference,
                'type' => TransactionType::WalletFunding->value,
                'amount' => $command->amountKobo,
            ]);

            return $txn;
        });

        // --- Provider charge (outside the DB transaction) ------------------
        $providerName = $command->provider !== null && $command->provider !== ''
            ? $command->provider
            : (string) config('ase.default_funding_provider', 'mock');

        $response = $this->gateway->charge(
            $providerName,
            new PaymentChargeRequest(
                $providerName,
                $total,
                $transaction->reference,
                $user->email,
                [
                    'method' => $command->method,
                    'customer_name' => (string) $user->name,
                    'customer_email' => (string) $user->email,
                    'customer_phone' => (string) $user->phone,
                ],
            ),
            $transaction,
        );

        // Async funding providers return customer-facing deposit
        // instructions (virtual account number, checkout URL, ...) — persist
        // them on the transaction so the API can show them and /verify knows
        // which provider to ask.
        if ($response->paymentDetails !== [] || $transaction->provider === null) {
            $transaction->update([
                'provider' => $providerName,
                // `metadata` is a nullable column; the array cast passes
                // null through, and null + array would fatal.
                'metadata' => ($transaction->metadata ?? []) + ['payment_details' => $response->paymentDetails],
            ]);
        }

        // --- Atomic outcome application ------------------------------------
        $this->applyOutcome(
            $transaction->reference,
            $providerName,
            $command->amountKobo,
            $fee,
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
     * Verify an ambiguous/verifying funding transaction against the
     * provider. Safe to call repeatedly; idempotent on the provider side.
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

            // Verify against the provider that actually took the charge, not
            // the platform default.
            $providerName = (string) ($txn->provider ?? config('ase.default_funding_provider', 'mock'));

            $response = $this->gateway->verifyCharge($providerName, $txn->provider_reference);

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
     * Apply a provider outcome to a funding transaction. Called inside a
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

            // Record which provider took the charge in every outcome — the
            // verification path must always ask the right provider.
            $txn->update([
                'provider' => $providerName,
                'provider_reference' => $providerReference ?? $txn->provider_reference,
            ]);

            if ($outcome === ProviderOutcome::DefinitiveSuccess) {
                $wallet = $this->wallets->forUser((int) $txn->user_id);

                $this->wallets->fund(
                    $wallet,
                    $amountKobo + $fee,
                    SystemAccounts::FUNDING_RECEIVABLE,
                    'Wallet funding ('.((string) ($txn->metadata['method'] ?? 'provider')).')',
                    'FUND_'.((string) $txn->reference),
                );

                $this->transactions->transition($txn, TransactionStatus::Success, 'funding confirmed', [
                    'provider_reference' => $providerReference,
                ]);
                $this->transactions->transition($txn, TransactionStatus::Completed, 'settled');

                $this->outbox->record('transaction', $txn->id, 'transaction.completed', [
                    'reference' => $txn->reference,
                    'type' => TransactionType::WalletFunding->value,
                    'amount' => $amountKobo,
                ]);
            } elseif ($outcome === ProviderOutcome::DefinitiveFailure) {
                $this->transactions->transition($txn, TransactionStatus::Failed, $error ?? 'funding failed');

                $this->outbox->record('transaction', $txn->id, 'transaction.failed', [
                    'reference' => $txn->reference,
                    'type' => TransactionType::WalletFunding->value,
                    'error' => $error,
                ]);
            } else {
                $status = TransactionStatus::from($txn->status);

                if ($status !== TransactionStatus::Verifying) {
                    $this->transactions->transition($txn, TransactionStatus::Ambiguous, $error ?? 'funding outcome unknown');
                    $this->transactions->transition($txn, TransactionStatus::Verifying, 'verification scheduled');
                }

                $this->outbox->record('transaction', $txn->id, 'transaction.ambiguous', [
                    'reference' => $txn->reference,
                    'type' => TransactionType::WalletFunding->value,
                ]);
            }
        });
    }
}
