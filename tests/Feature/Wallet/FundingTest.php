<?php

namespace Tests\Feature\Wallet;

use App\Domain\Ledger\Services\LedgerService;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

final class FundingTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    private function fundedUser(int $amountKobo, string $idempotencyKey = 'fund-key-1'): array
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', ['amount' => $amountKobo]);

        return [$user, $token, $response];
    }

    public function test_wallet_can_be_funded(): void
    {
        [$user, $token, $response] = $this->fundedUser(100000); // ₦1,000

        $reference = $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'COMPLETED')
            ->json('data.reference');

        $this->assertNotNull($reference);

        $wallet = $user->wallet->fresh();
        $this->assertSame(100000, (int) $wallet->control_balance);
        $this->assertSame(0, (int) $wallet->reserved_balance);
        $this->assertSame(100000, $wallet->availableBalance());

        // Ledger must be balanced after a funding.
        $report = app(LedgerService::class)->integrityReport();
        $this->assertTrue($report['balanced']);
        $this->assertSame(100000, $report['total_debits']);
    }

    public function test_balance_endpoint_reports_balances(): void
    {
        [$user, $token] = $this->fundedUser(250000);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/wallet/balance')
            ->assertOk()
            ->assertJsonPath('data.available_balance', 250000)
            ->assertJsonPath('data.control_balance', 250000)
            ->assertJsonPath('data.reserved_balance', 0);
    }

    public function test_funding_failure_leaves_wallet_untouched(): void
    {
        config(['ase.mock.funding_mode' => 'failure']);

        [$user, $token, $response] = $this->fundedUser(100000, 'fund-fail-1');

        $response->assertOk()
            ->assertJsonPath('data.status', 'FAILED');

        $wallet = $user->wallet->fresh();
        $this->assertSame(0, (int) $wallet->control_balance);
        $this->assertSame(0, (int) $wallet->reserved_balance);
    }

    public function test_funding_timeout_enters_verification_not_failure(): void
    {
        config(['ase.mock.funding_mode' => 'timeout']);

        [, $token, $response] = $this->fundedUser(100000, 'fund-timeout-1');

        $reference = $response->assertOk()
            ->assertJsonPath('data.status', 'VERIFYING')
            ->json('data.reference');

        // Wallet is NOT credited while the outcome is unknown.
        $user = \App\Models\User::first();
        $this->assertSame(0, (int) $user->wallet->control_balance);

        // Verification is attempted; with no provider reference the mock
        // cannot confirm, so the transaction stays in VERIFYING.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/v1/transactions/{$reference}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'VERIFYING');
    }

    public function test_daily_funding_limit_by_kyc_tier_blocks_overspend(): void
    {
        config(['ase.mock.funding_mode' => 'success']);

        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        // Tier 0: per-transaction ₦50,000 (5000000), daily amount ₦100,000
        // (10000000), daily count 5.
        // 4 × ₦25,000 (2500000 kobo) = ₦100,000 — still under the count
        // limit (4) and at the amount limit. The 5th pushes the daily
        // AMOUNT over (count is still exactly at its limit).
        for ($i = 1; $i <= 4; $i++) {
            $this->withHeaders($this->authHeaders($token))
                ->withHeader('Idempotency-Key', "daily-{$i}")
                ->withHeader('X-Transaction-Pin', '1234')
                ->postJson('/api/v1/wallet/fund', ['amount' => 2500000])
                ->assertOk()
                ->assertJsonPath('data.status', 'COMPLETED');
        }

        $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'daily-5')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', ['amount' => 2500000])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'RISK_BLOCKED');

        $assessment = \App\Models\RiskAssessment::latest('id')->first();
        $this->assertContains('daily_amount_limit_exceeded', $assessment->signals);
    }

    public function test_wallet_transactions_endpoint_lists_transactions(): void
    {
        [$user, $token] = $this->fundedUser(100000);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/wallet/transactions')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }
}
