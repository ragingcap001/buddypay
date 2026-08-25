<?php

namespace Tests\Feature\Transactions;

use App\Domain\Ledger\Constants\SystemAccounts;
use App\Domain\Ledger\Enums\EntryDirection;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Wallet\Enums\WalletReservationStatus;
use App\Models\LedgerEntry;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

final class BillPaymentTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    private function userWithWallet(int $balanceKobo, string $phone = '08031234567', int $pin = 1234): array
    {
        [$user, $token] = $this->verifiedUser($phone);
        $user->update(['pin_hash' => Hash::make((string) $pin)]);
        $user->wallet->update(['control_balance' => $balanceKobo]);

        return [$user, $token];
    }

    public function test_successful_airtime_payment(): void
    {
        [$user, $token] = $this->userWithWallet(500000); // ₦5,000

        $response = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'airtime-1')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/bills/pay', [
                'type' => 'AIRTIME',
                'amount' => 100000, // ₦1,000
                'phone' => '08039990001',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $reference = $response->json('data.reference');
        $transaction = Transaction::where('reference', $reference)->firstOrFail();

        // Fee: airtime = ₦5 flat (500 kobo) → total debited 100500.
        $this->assertSame(500, (int) $transaction->fee);

        // Wallet: control and reserved both drop by the total.
        $wallet = $user->wallet->fresh();
        $this->assertSame(399500, (int) $wallet->control_balance);
        $this->assertSame(0, (int) $wallet->reserved_balance);

        // Reservation is committed.
        $reservation = WalletReservation::find($transaction->reservation_id);
        $this->assertSame(WalletReservationStatus::Committed->value, $reservation->status);

        // Ledger entries: DR customer wallet 100500 / CR provider payable 100000 + CR fee revenue 500.
        $ledgerTransaction = \App\Models\LedgerTransaction::where('reference', 'SETTLE_'.$reservation->reference)->firstOrFail();
        $entries = LedgerEntry::with('account')->where('ledger_transaction_id', $ledgerTransaction->id)->get();
        $byAccount = $entries->groupBy(fn (LedgerEntry $entry): string => $entry->account->code);

        $this->assertSame(100500, (int) $byAccount[SystemAccounts::walletAccountCode($user->id)]->first()->amount);
        $this->assertSame(EntryDirection::Debit->value, $byAccount[SystemAccounts::walletAccountCode($user->id)]->first()->direction);
        $this->assertSame(100000, (int) $byAccount[SystemAccounts::PROVIDER_PAYABLE]->first()->amount);
        $this->assertSame(EntryDirection::Credit->value, $byAccount[SystemAccounts::PROVIDER_PAYABLE]->first()->direction);
        $this->assertSame(500, (int) $byAccount[SystemAccounts::REVENUE_TRANSACTION_FEE]->first()->amount);

        // Global ledger invariant.
        $this->assertTrue(app(LedgerService::class)->integrityReport()['balanced']);

        // A provider attempt was recorded.
        $this->assertDatabaseHas('provider_attempts', [
            'transaction_id' => $transaction->id,
            'type' => 'PURCHASE',
            'status' => 'SUCCESS',
        ]);

        // Transaction lifecycle is fully auditable.
        $this->assertGreaterThanOrEqual(4, $transaction->events()->count());
    }

    public function test_insufficient_balance_blocks_payment(): void
    {
        [$user, $token] = $this->userWithWallet(50000); // ₦500

        $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'too-big-1')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/bills/pay', [
                'type' => 'AIRTIME',
                'amount' => 100000,
                'phone' => '08039990001',
            ])->assertStatus(422)
            ->assertJsonPath('error.code', 'INSUFFICIENT_BALANCE');

        // Nothing was charged and no transaction was created.
        $wallet = $user->wallet->fresh();
        $this->assertSame(50000, (int) $wallet->control_balance);
        $this->assertSame(0, (int) $wallet->reserved_balance);
        $this->assertSame(0, Transaction::count());
    }

    public function test_definitive_provider_failure_releases_reservation(): void
    {
        [$user, $token] = $this->userWithWallet(500000);

        // Phone numbers ending in 888 fail definitively at the mock provider.
        $response = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'provider-fail-1')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/bills/pay', [
                'type' => 'AIRTIME',
                'amount' => 100000,
                'phone' => '08039990888',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'FAILED');

        $wallet = $user->wallet->fresh();
        $this->assertSame(500000, (int) $wallet->control_balance, 'Failed payment must not debit the wallet');
        $this->assertSame(0, (int) $wallet->reserved_balance, 'Reservation must be released on failure');

        $reservation = WalletReservation::first();
        $this->assertSame(WalletReservationStatus::Released->value, $reservation->status);

        // No settlement ledger entries were posted.
        $this->assertFalse(
            app(LedgerService::class)->integrityReport()['total_debits'] > 0,
            'No ledger movement should occur for a definitively failed payment',
        );
    }

    public function test_ambiguous_provider_outcome_moves_to_verifying_and_holds_funds(): void
    {
        config(['ase.mock.mode' => 'timeout']);

        [$user, $token] = $this->userWithWallet(500000);

        $response = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'ambiguous-1')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/bills/pay', [
                'type' => 'DATA',
                'amount' => 100000,
                'phone' => '08039990001',
            ]);

        $reference = $response->assertOk()
            ->assertJsonPath('data.status', 'VERIFYING')
            ->json('data.reference');

        // Funds remain HELD (reserved) while the outcome is unknown.
        // Data fee: 100 bps (1%) of 100000 = 1000 → total 101000.
        $wallet = $user->wallet->fresh();
        $this->assertSame(500000, (int) $wallet->control_balance);
        $this->assertSame(101000, (int) $wallet->reserved_balance);

        // No ledger entries yet.
        $this->assertSame(0, LedgerEntry::count());

        // Verification against the mock with a known reference confirms success.
        $transaction = Transaction::where('reference', $reference)->firstOrFail();
        $transaction->update(['provider_reference' => 'MOCK_0000']);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/v1/transactions/{$reference}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $wallet = $user->wallet->fresh();
        $this->assertSame(399000, (int) $wallet->control_balance);
        $this->assertSame(0, (int) $wallet->reserved_balance);
        $this->assertTrue(app(LedgerService::class)->integrityReport()['balanced']);
    }

    public function test_users_cannot_view_other_users_transactions(): void
    {
        [$userA, $tokenA] = $this->userWithWallet(500000, '08031111111');
        $this->userWithWallet(100000, '08032222222');

        $response = $this->withHeaders($this->authHeaders($tokenA))
            ->withHeader('Idempotency-Key', 'ownership-1')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/bills/pay', [
                'type' => 'AIRTIME',
                'amount' => 100000,
                'phone' => '08039990001',
            ]);

        $reference = $response->json('data.reference');

        // User B tries to read user A's transaction.
        $userB = User::where('phone', '08032222222')->first();
        $tokenB = $userB->createToken('test')->plainTextToken;

        $this->withHeaders($this->authHeaders($tokenB))
            ->getJson("/api/v1/transactions/{$reference}")
            ->assertStatus(404);
    }

    public function test_bill_catalog_endpoints(): void
    {
        [, $token] = $this->userWithWallet(100000);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/bills/categories')
            ->assertOk()
            ->assertJsonCount(5, 'data');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/bills/products?category=airtime')
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $validate = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/bills/validate', [
                'type' => 'AIRTIME',
                'phone' => '08039990001',
            ]);

        $validate->assertOk()
            ->assertJsonPath('data.valid', true);
    }
}
