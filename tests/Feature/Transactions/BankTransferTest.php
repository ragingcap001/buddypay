<?php

namespace Tests\Feature\Transactions;

use App\Domain\Ledger\Services\LedgerService;
use App\Models\Transaction;
use App\Models\WalletReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

final class BankTransferTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    /**
     * Create a user whose wallet holds $balanceKobo (funded via the mock
     * provider) plus a PIN and auth headers.
     *
     * @return array{0: \App\Models\User, 1: string}
     */
    private function fundedUser(int $balanceKobo, string $fundKey = 'payout-fund'): array
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', $fundKey)
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', ['amount' => $balanceKobo])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        return [$user, $token];
    }

    private function payoutHeaders(string $token): array
    {
        return array_merge($this->authHeaders($token), [
            'Idempotency-Key' => 'payout-key-1',
            'X-Transaction-Pin' => '1234',
        ]);
    }

    public function test_payout_success_debits_wallet_and_posts_ledger(): void
    {
        config(['ase.mock.payout_mode' => 'success']);
        // bank_transfer fee: 100 bps + 100 kobo → ₦2,500 + ₦26 = ₦2,526
        [$user, $token] = $this->fundedUser(1000000); // ₦10,000

        $response = $this->withHeaders($this->payoutHeaders($token))
            ->postJson('/api/v1/wallet/payout', [
                'amount' => 250000,
                'bank_code' => '035',
                'account_number' => '0123456789',
                'account_name' => 'JOHN DOE',
            ]);

        $reference = $response->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.type', 'BANK_TRANSFER')
            ->json('data.reference');

        $this->assertNotNull($reference);

        // Wallet debited by amount + fee.
        $wallet = $user->wallet->fresh();
        $this->assertSame(1000000 - 250000 - 2600, (int) $wallet->control_balance);
        $this->assertSame(0, (int) $wallet->reserved_balance);

        // Reservation committed.
        $txn = Transaction::where('reference', $reference)->first();
        $reservation = WalletReservation::find($txn->reservation_id);
        $this->assertSame(
            \App\Domain\Wallet\Enums\WalletReservationStatus::Committed->value,
            $reservation->status,
        );

        // Ledger balanced.
        $report = app(LedgerService::class)->integrityReport();
        $this->assertTrue($report['balanced']);
    }

    public function test_payout_failure_releases_reservation(): void
    {
        config(['ase.mock.payout_mode' => 'failure']);
        [$user, $token] = $this->fundedUser(1000000);

        $response = $this->withHeaders($this->payoutHeaders($token))
            ->postJson('/api/v1/wallet/payout', [
                'amount' => 250000,
                'bank_code' => '035',
                'account_number' => '0123456789',
                'account_name' => 'JOHN DOE',
            ]);

        $response->assertOk()->assertJsonPath('data.status', 'FAILED');

        $wallet = $user->wallet->fresh();
        $this->assertSame(1000000, (int) $wallet->control_balance);
        $this->assertSame(0, (int) $wallet->reserved_balance);

        $this->assertTrue(app(LedgerService::class)->integrityReport()['balanced']);
    }

    public function test_payout_timeout_enters_verifying_and_holds_funds(): void
    {
        config(['ase.mock.payout_mode' => 'timeout']);
        [$user, $token] = $this->fundedUser(1000000);

        $reference = $this->withHeaders($this->payoutHeaders($token))
            ->postJson('/api/v1/wallet/payout', [
                'amount' => 250000,
                'bank_code' => '035',
                'account_number' => '0123456789',
                'account_name' => 'JOHN DOE',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'VERIFYING')
            ->json('data.reference');

        // Funds stay RESERVED (available drops) until the provider confirms.
        $wallet = $user->wallet->fresh();
        $this->assertSame(1000000, (int) $wallet->control_balance);
        $this->assertSame(252600, (int) $wallet->reserved_balance);

        // Verification with no provider reference yet keeps it VERIFYING.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/v1/transactions/{$reference}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'VERIFYING');
    }

    public function test_payout_insufficient_balance_is_rejected(): void
    {
        config(['ase.mock.payout_mode' => 'success']);
        [$user, $token] = $this->fundedUser(100000); // ₦1,000

        $this->withHeaders($this->payoutHeaders($token))
            ->postJson('/api/v1/wallet/payout', [
                'amount' => 250000,
                'bank_code' => '035',
                'account_number' => '0123456789',
                'account_name' => 'JOHN DOE',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INSUFFICIENT_BALANCE');

        $this->assertSame(0, Transaction::where('type', 'BANK_TRANSFER')->count());
    }

    public function test_payout_idempotent_replay_returns_original_result(): void
    {
        config(['ase.mock.payout_mode' => 'success']);
        [$user, $token] = $this->fundedUser(1000000);

        $body = [
            'amount' => 250000,
            'bank_code' => '035',
            'account_number' => '0123456789',
            'account_name' => 'JOHN DOE',
        ];

        $first = $this->withHeaders($this->payoutHeaders($token))
            ->postJson('/api/v1/wallet/payout', $body)
            ->assertOk()
            ->json('data.reference');

        $second = $this->withHeaders($this->payoutHeaders($token))
            ->postJson('/api/v1/wallet/payout', $body)
            ->assertOk();

        $this->assertSame($first, $second->json('data.reference'));
        $this->assertSame(1, Transaction::where('type', 'BANK_TRANSFER')->count());
        // Debited exactly once.
        $this->assertSame(1000000 - 252600, (int) $user->wallet->fresh()->control_balance);
    }

    public function test_payout_same_key_different_body_is_rejected(): void
    {
        config(['ase.mock.payout_mode' => 'success']);
        [$user, $token] = $this->fundedUser(1000000);

        $this->withHeaders($this->payoutHeaders($token))
            ->postJson('/api/v1/wallet/payout', [
                'amount' => 250000,
                'bank_code' => '035',
                'account_number' => '0123456789',
                'account_name' => 'JOHN DOE',
            ])
            ->assertOk();

        $this->withHeaders($this->payoutHeaders($token))
            ->postJson('/api/v1/wallet/payout', [
                'amount' => 500000, // different body, same key
                'bank_code' => '035',
                'account_number' => '0123456789',
                'account_name' => 'JOHN DOE',
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');
    }
}
