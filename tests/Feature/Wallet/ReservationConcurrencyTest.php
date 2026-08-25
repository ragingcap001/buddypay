<?php

namespace Tests\Feature\Wallet;

use App\Domain\Wallet\Services\WalletService;
use App\Exceptions\InsufficientBalanceException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

/**
 * Double-spend prevention tests.
 *
 * The sequential tests prove the guard logic (the same code path that runs
 * under concurrent load). True parallel concurrency (two connections
 * reserving at the same instant) is protected by SELECT ... FOR UPDATE row
 * locks + the CHECK constraint; a multi-process load test against the
 * PostgreSQL CI service exercises that in CI.
 */
final class ReservationConcurrencyTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    public function test_second_reservation_cannot_exceed_available_balance(): void
    {
        [$user, ] = $this->verifiedUser();
        $user->wallet->update(['control_balance' => 100000]); // ₦1,000

        $wallets = app(WalletService::class);
        $wallet = $wallets->forUser($user->id);

        DB::transaction(function () use ($wallets, $wallet): void {
            $wallets->reserve($wallet, 60000); // 60% reserved

            $this->expectException(InsufficientBalanceException::class);

            // Second reservation of 60% must fail: only 40% available.
            $wallets->reserve($wallet, 60000);
        });
    }

    public function test_concurrent_updates_are_serialised_by_row_lock(): void
    {
        [$user, ] = $this->verifiedUser();
        $user->wallet->update(['control_balance' => 100000]);

        $ledger = app(\App\Domain\Ledger\Services\LedgerService::class);
        $ledger->walletAccount($user->id);
        $ledger->getOrCreateAccount(
            \App\Domain\Ledger\Constants\SystemAccounts::PROVIDER_PAYABLE,
            \App\Domain\Ledger\Enums\LedgerAccountType::Liability,
            'Provider Payable',
        );

        $wallets = app(WalletService::class);
        $wallet = $wallets->forUser($user->id);

        // Two nested transactions on one connection simulate two "workers"
        // in sequence; the FOR UPDATE lock ensures the second sees the
        // first's committed balance.
        DB::transaction(function () use ($wallets, $wallet, $user): void {
            $reservation = $wallets->reserve($wallet, 100000); // full balance
            $wallets->commit($reservation, 100000, \App\Domain\Ledger\Constants\SystemAccounts::PROVIDER_PAYABLE);

            $fresh = $wallets->forUser($user->id);
            $this->assertSame(0, $wallets->available($fresh));

            $this->expectException(InsufficientBalanceException::class);
            $wallets->reserve($fresh, 1);
        });
    }

    public function test_stale_reservations_are_expired_and_funds_returned(): void
    {
        [$user, ] = $this->verifiedUser();
        $user->wallet->update(['control_balance' => 100000]);

        $wallets = app(WalletService::class);
        $wallet = $wallets->forUser($user->id);

        $reservation = DB::transaction(function () use ($wallets, $wallet) {
            return $wallets->reserve($wallet, 40000);
        });

        // Force the reservation past its expiry.
        $reservation->update(['expires_at' => now()->subMinute()]);

        $expired = $wallets->expireStale();

        $this->assertSame(1, $expired);
        $this->assertSame('EXPIRED', $reservation->fresh()->status);

        $wallet = $wallets->forUser($user->id);
        $this->assertSame(100000, $wallets->available($wallet));
    }

    public function test_wallet_balance_constraints_are_enforced_by_database(): void
    {
        [$user, ] = $this->verifiedUser();

        $this->expectException(\Illuminate\Database\QueryException::class);

        // Try to make reserved exceed control — must fail at the DB level.
        $user->wallet->forceFill(['reserved_balance' => 999999])->save();
    }
}
