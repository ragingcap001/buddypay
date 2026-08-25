<?php

namespace Tests\Feature\Transactions;

use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

final class IdempotencyTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    public function test_duplicate_request_returns_original_result_without_double_charging(): void
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);
        $user->wallet->update(['control_balance' => 500000]);

        $body = [
            'type' => 'AIRTIME',
            'amount' => 100000,
            'phone' => '08039990001',
        ];

        $headers = $this->authHeaders($token);
        $headers['Idempotency-Key'] = 'idem-1';
        $headers['X-Transaction-Pin'] = '1234';

        $first = $this->withHeaders($headers)->postJson('/api/v1/bills/pay', $body);
        $second = $this->withHeaders($headers)->postJson('/api/v1/bills/pay', $body);

        $first->assertOk();
        $second->assertOk();

        // The second call returns the original result.
        $this->assertSame($first->json('data.reference'), $second->json('data.reference'));

        // Exactly one transaction exists and the wallet was debited once.
        $this->assertSame(1, Transaction::count());
        $this->assertSame(399500, (int) $user->wallet->fresh()->control_balance);

        // The stored response was used (no second provider call for the payment).
        $this->assertSame(1, \App\Models\ProviderAttempt::count());
    }

    public function test_same_key_with_different_body_is_rejected(): void
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);
        $user->wallet->update(['control_balance' => 500000]);

        $headers = $this->authHeaders($token);
        $headers['Idempotency-Key'] = 'idem-2';
        $headers['X-Transaction-Pin'] = '1234';

        $this->withHeaders($headers)
            ->postJson('/api/v1/bills/pay', [
                'type' => 'AIRTIME',
                'amount' => 100000,
                'phone' => '08039990001',
            ])->assertOk();

        $this->withHeaders($headers)
            ->postJson('/api/v1/bills/pay', [
                'type' => 'AIRTIME',
                'amount' => 200000, // different amount → different request
                'phone' => '08039990001',
            ])->assertStatus(409)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REUSED');

        $this->assertSame(1, Transaction::count());
    }

    public function test_missing_idempotency_key_is_rejected(): void
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $this->withHeaders($this->authHeaders($token))
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/bills/pay', [
                'type' => 'AIRTIME',
                'amount' => 100000,
                'phone' => '08039990001',
            ])->assertStatus(400)
            ->assertJsonPath('error.code', 'IDEMPOTENCY_KEY_REQUIRED');

        $this->assertSame(0, Transaction::count());
    }

    public function test_idempotency_is_scoped_per_user(): void
    {
        // Two different users can reuse the same key with the same body.
        [$userA, $tokenA] = $this->verifiedUser('08031111111');
        $userA->update(['pin_hash' => Hash::make('1234')]);
        $userA->wallet->update(['control_balance' => 500000]);

        $this->verifiedUser('08032222222');
        $userB = \App\Models\User::where('phone', '08032222222')->first();
        $userB->update(['pin_hash' => Hash::make('1234')]);
        $userB->wallet->update(['control_balance' => 500000]);
        $tokenB = $userB->createToken('test')->plainTextToken;

        $body = ['type' => 'AIRTIME', 'amount' => 100000, 'phone' => '08039990001'];

        $headersA = $this->authHeaders($tokenA);
        $headersA['Idempotency-Key'] = 'shared-key';
        $headersA['X-Transaction-Pin'] = '1234';

        $headersB = $this->authHeaders($tokenB);
        $headersB['Idempotency-Key'] = 'shared-key';
        $headersB['X-Transaction-Pin'] = '1234';

        $this->withHeaders($headersA)->postJson('/api/v1/bills/pay', $body)->assertOk();
        $this->withHeaders($headersB)->postJson('/api/v1/bills/pay', $body)->assertOk();

        $this->assertSame(2, Transaction::count());
    }
}
