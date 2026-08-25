<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

final class PinTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    public function test_pin_requires_password_to_set(): void
    {
        [, $token] = $this->verifiedUser();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/auth/pin', [
                'password' => 'wrongpass',
                'pin' => '1234',
            ])->assertStatus(401);
    }

    public function test_pin_can_be_set_and_verified(): void
    {
        [, $token] = $this->verifiedUser();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/auth/pin', [
                'password' => 'password',
                'pin' => '1234',
            ])->assertOk();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/auth/verify-pin', ['pin' => '1234'])
            ->assertOk()
            ->assertJsonPath('data.valid', true);

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/auth/verify-pin', ['pin' => '9999'])
            ->assertOk()
            ->assertJsonPath('data.valid', false);

        $user = \App\Models\User::first();
        $this->assertTrue(Hash::check('1234', $user->pin_hash), 'PIN must be hashed, never plaintext');
        $this->assertNotSame('1234', $user->pin_hash);
    }

    public function test_financial_endpoint_without_pin_is_rejected(): void
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'fund-test-1')
            ->postJson('/api/v1/wallet/fund', ['amount' => 100000])
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'PIN_REQUIRED');
    }

    public function test_financial_endpoint_with_wrong_pin_is_rejected(): void
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'fund-test-2')
            ->withHeader('X-Transaction-Pin', '9999')
            ->postJson('/api/v1/wallet/fund', ['amount' => 100000])
            ->assertStatus(401)
            ->assertJsonPath('error.code', 'PIN_INVALID');
    }
}
