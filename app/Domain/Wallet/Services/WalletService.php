<?php

namespace App\Domain\Wallet\Services;

use App\Domain\Ledger\Constants\SystemAccounts;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Ledger\ValueObjects\LedgerLine;
use App\Domain\Transactions\Support\ReferenceGenerator;
use App\Domain\Wallet\Enums\WalletReservationStatus;
use App\Exceptions\FinancialException;
use App\Exceptions\InsufficientBalanceException;
use App\Models\LedgerTransaction;
use App\Models\Wallet;
use App\Models\WalletReservation;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Wallet balance management with atomic reservations.
 *
 * Available balance = control_balance - reserved_balance.
 *
 * Every mutation re-locks the wallet row (SELECT ... FOR UPDATE) inside the
 * caller's database transaction, so concurrent requests cannot double spend.
 * The database also enforces reserved_balance <= control_balance.
 */
final class WalletService
{
    public function __construct(private readonly LedgerService $ledger)
    {
    }

    public function forUser(int $userId): Wallet
    {
        return Wallet::where('user_id', $userId)->firstOrFail();
    }

    public function available(Wallet $wallet): int
    {
        return (int) $wallet->control_balance - (int) $wallet->reserved_balance;
    }

    /**
     * Create the wallet (and its ledger account) for a new user.
     * Must be called inside a database transaction with the user creation.
     */
    public function createUserWallet(int $userId): Wallet
    {
        $this->ledger->walletAccount($userId);

        return Wallet::create([
            'user_id' => $userId,
            'currency' => config('ase.base_currency', 'NGN'),
            'control_balance' => 0,
            'reserved_balance' => 0,
        ]);
    }

    /**
     * Deposit funds into a wallet and post the balanced ledger entries.
     *
     * Debit  funding source (e.g. FUNDING_RECEIVABLE)   amount
     * Credit customer wallet (liability to customer)    amount
     *
     * Must be called inside a database transaction.
     */
    public function fund(
        Wallet $wallet,
        int $amount,
        string $fundingSourceAccount,
        string $description,
        ?string $ledgerReference = null,
    ): LedgerTransaction {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Funding amount must be a positive integer in minor units.');
        }

        $fresh = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();

        $fresh->control_balance += $amount;
        $fresh->save();

        return $this->ledger->post($description, [
            LedgerLine::debit($fundingSourceAccount, $amount),
            LedgerLine::credit(SystemAccounts::walletAccountCode($fresh->user_id), $amount),
        ], $ledgerReference);
    }

    /**
     * Atomically reserve funds from a wallet.
     *
     * Must be called inside a database transaction. Throws
     * InsufficientBalanceException when the available balance is too low.
     */
    public function reserve(Wallet $wallet, int $amount, ?string $reference = null, ?Carbon $expiresAt = null): WalletReservation
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Reservation amount must be a positive integer in minor units.');
        }

        $fresh = Wallet::whereKey($wallet->id)->lockForUpdate()->firstOrFail();

        $available = $this->available($fresh);

        if ($amount > $available) {
            throw new InsufficientBalanceException($available, $amount);
        }

        $fresh->reserved_balance += $amount;
        $fresh->save();

        return WalletReservation::create([
            'wallet_id' => $fresh->id,
            'reference' => $reference ?? ReferenceGenerator::reservation(),
            'amount' => $amount,
            'status' => WalletReservationStatus::Active->value,
            'expires_at' => $expiresAt ?? now()->addMinutes((int) config('ase.wallet.reservation_ttl_minutes', 15)),
        ]);
    }

    /**
     * Commit an active reservation: the reserved funds leave the wallet and
     * are settled against the destination account (e.g. provider payable),
     * with any fee booked to the fee account.
     *
     * Debit  customer wallet (liability to customer)          amount + fee
     * Credit destination (e.g. PROVIDER_PAYABLE)             amount
     * Credit fee account (e.g. REVENUE_TRANSACTION_FEE)      fee
     *
     * Must be called inside a database transaction.
     */
    public function commit(
        WalletReservation $reservation,
        int $destinationAmount,
        string $destinationAccount,
        ?int $feeAmount = null,
        ?string $feeAccount = null,
        string $description = 'Transaction settlement',
    ): LedgerTransaction {
        $this->assertActive($reservation);

        $fee = $feeAmount ?? 0;

        if ($destinationAmount + $fee !== (int) $reservation->amount) {
            throw new InvalidArgumentException('Commit amounts do not match the reserved total.');
        }

        $wallet = Wallet::whereKey($reservation->wallet_id)->lockForUpdate()->firstOrFail();

        // Both columns must drop in one statement: a reservation can cover
        // the wallet's entire balance, and lowering control_balance alone
        // first would momentarily leave reserved > control, tripping the
        // wallets_balances_valid CHECK constraint.
        $wallet->control_balance -= (int) $reservation->amount;
        $wallet->reserved_balance -= (int) $reservation->amount;
        $wallet->save();

        $reservation->update([
            'status' => WalletReservationStatus::Committed->value,
            'committed_at' => now(),
        ]);

        $lines = [
            LedgerLine::debit(SystemAccounts::walletAccountCode($wallet->user_id), (int) $reservation->amount),
            LedgerLine::credit($destinationAccount, $destinationAmount),
        ];

        if ($fee > 0) {
            $lines[] = LedgerLine::credit($feeAccount ?? SystemAccounts::REVENUE_TRANSACTION_FEE, $fee);
        }

        return $this->ledger->post($description, $lines, 'SETTLE_'.((string) $reservation->reference));
    }

    /**
     * Release an active reservation back to the available balance.
     * No ledger entries are posted — a reservation is not a money movement.
     *
     * Must be called inside a database transaction.
     */
    public function release(WalletReservation $reservation, string $reason = 'released'): void
    {
        $this->settleReservation($reservation, WalletReservationStatus::Released, $reason);
    }

    /**
     * Expire stale active reservations. Returns the number expired.
     */
    public function expireStale(int $batchSize = 200): int
    {
        $stale = WalletReservation::where('status', WalletReservationStatus::Active->value)
            ->where('expires_at', '<=', now())
            ->limit($batchSize)
            ->get();

        $count = 0;

        foreach ($stale as $reservation) {
            DB::transaction(function () use ($reservation, &$count): void {
                $this->settleReservation($reservation, WalletReservationStatus::Expired, 'expired');
                $count++;
            });
        }

        return $count;
    }

    private function settleReservation(WalletReservation $reservation, WalletReservationStatus $status, string $reason): void
    {
        $this->assertActive($reservation);

        $wallet = Wallet::whereKey($reservation->wallet_id)->lockForUpdate()->firstOrFail();

        $wallet->reserved_balance -= (int) $reservation->amount;
        $wallet->save();

        $updates = ['status' => $status->value];

        if ($status === WalletReservationStatus::Committed) {
            $updates['committed_at'] = now();
        } else {
            $updates['released_at'] = now();
            $updates['release_reason'] = $reason;
        }

        $reservation->update($updates);
    }

    private function assertActive(WalletReservation $reservation): void
    {
        $status = WalletReservationStatus::from($reservation->status);

        if ($status !== WalletReservationStatus::Active) {
            throw new FinancialException('RESERVATION_NOT_ACTIVE', "Reservation [{$reservation->reference}] is not active (status: {$status->value}).", 409);
        }
    }
}
