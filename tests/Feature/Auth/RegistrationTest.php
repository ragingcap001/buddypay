<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

final class RegistrationTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    public function test_user_can_register_and_verify_otp(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'Chidi Okafor',
            'phone' => '08031234567',
            'password' => 'supersecret1',
            'password_confirmation' => 'supersecret1',
        ]);

        $response->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user.phone', '08031234567')
            ->assertJsonStructure(['data' => ['user', 'dev_otp']]);

        $otp = $response->json('data.dev_otp');
        $this->assertNotEmpty($otp);

        $verify = $this->postJson('/api/v1/auth/verify-otp', [
            'phone' => '08031234567',
            'otp' => $otp,
        ]);

        $verify->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['user', 'wallet', 'token']]);

        $user = User::where('phone', '08031234567')->first();
        $this->assertNotNull($user, 'User should exist after registration');
        $this->assertNotNull($user->phone_verified_at, 'Phone should be verified after OTP verification');
        $this->assertNotNull($user->wallet, 'Wallet should be created at registration');
        $this->assertSame(0, (int) $user->wallet->control_balance);
        $this->assertNotNull($user->kycProfile);
    }

    public function test_duplicate_phone_number_is_rejected(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Chidi Okafor',
            'phone' => '08031234567',
            'password' => 'supersecret1',
            'password_confirmation' => 'supersecret1',
        ])->assertCreated();

        $this->postJson('/api/v1/auth/register', [
            'name' => 'Someone Else',
            'phone' => '+2348031234567', // normalised to the same number
            'password' => 'supersecret1',
            'password_confirmation' => 'supersecret1',
        ])->assertStatus(409)
            ->assertJsonPath('error.code', 'USER_EXISTS');

        $this->assertSame(1, User::count());
    }

    public function test_wrong_otp_is_rejected_and_attempt_is_tracked(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Chidi Okafor',
            'phone' => '08031234567',
            'password' => 'supersecret1',
            'password_confirmation' => 'supersecret1',
        ]);

        $this->postJson('/api/v1/auth/verify-otp', [
            'phone' => '08031234567',
            'otp' => '000000',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'OTP_INVALID');

        $this->assertSame(1, \App\Models\OtpChallenge::first()->attempts);
    }

    public function test_login_before_otp_verification_is_blocked(): void
    {
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Chidi Okafor',
            'phone' => '08031234567',
            'password' => 'supersecret1',
            'password_confirmation' => 'supersecret1',
        ]);

        $this->postJson('/api/v1/auth/login', [
            'phone' => '08031234567',
            'password' => 'supersecret1',
        ])->assertStatus(403)
            ->assertJsonPath('error.code', 'UNVERIFIED_USER');
    }

    public function test_login_with_wrong_credentials_is_rejected(): void
    {
        [$user, ] = $this->verifiedUser();

        $this->postJson('/api/v1/auth/login', [
            'phone' => $user->phone,
            'password' => 'wrongpassword',
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'INVALID_CREDENTIALS');
    }

    public function test_login_after_verification_returns_token(): void
    {
        [$user, ] = $this->verifiedUser();

        $this->postJson('/api/v1/auth/login', [
            'phone' => $user->phone,
            'password' => 'password',
        ])->assertOk()
            ->assertJsonStructure(['data' => ['user', 'wallet', 'token']]);
    }

    public function test_logout_invalidates_the_current_token(): void
    {
        [, $token] = $this->verifiedUser();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/auth/logout')
            ->assertOk();

        // The same token no longer works.
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/wallet/balance')
            ->assertUnauthorized();
    }
}
