<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

final class PasswordResetTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    public function test_password_can_be_reset_with_token(): void
    {
        [$user, ] = $this->verifiedUser();

        $forgot = $this->postJson('/api/v1/auth/forgot-password', [
            'phone' => $user->phone,
        ]);

        $forgot->assertOk();
        $token = $forgot->json('data.dev_reset_token');
        $this->assertNotEmpty($token);

        $reset = $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'password' => 'brandnewpass1',
            'password_confirmation' => 'brandnewpass1',
        ]);

        $reset->assertOk();

        // New password works, old one does not.
        $this->postJson('/api/v1/auth/login', [
            'phone' => $user->phone,
            'password' => 'brandnewpass1',
        ])->assertOk();

        $this->postJson('/api/v1/auth/login', [
            'phone' => $user->phone,
            'password' => 'password',
        ])->assertStatus(401);

        // Token is single-use.
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token,
            'password' => 'anotherpass1',
            'password_confirmation' => 'anotherpass1',
        ])->assertStatus(422)
            ->assertJsonPath('error.code', 'RESET_TOKEN_INVALID');
    }

    public function test_forgot_password_does_not_reveal_account_existence(): void
    {
        $a = $this->postJson('/api/v1/auth/forgot-password', ['phone' => '08039999999']);
        $b = $this->postJson('/api/v1/auth/forgot-password', ['phone' => '08039999998']);

        $a->assertOk();
        $b->assertOk();
        $this->assertSame($a->json('data.message'), $b->json('data.message'));
    }
}
