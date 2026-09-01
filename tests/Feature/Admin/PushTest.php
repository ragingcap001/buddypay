<?php

namespace Tests\Feature\Admin;

use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

final class PushTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function admin(): User
    {
        return User::factory()->create([
            'phone' => '08030000002',
            'role' => User::ROLE_ADMIN,
            'phone_verified_at' => now(),
        ]);
    }

    /**
     * A structurally valid Firebase service account with a freshly
     * generated RSA key (so the JWT can actually be signed).
     */
    private function serviceAccountJson(): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        return (string) json_encode([
            'type' => 'service_account',
            'client_email' => 'fcm-test@ase.iam.gserviceaccount.com',
            'private_key' => $pem,
            'project_id' => 'ase-fcm-test',
        ]);
    }

    private function configureFirebase(): void
    {
        AppConfig::create([
            'key' => 'firebase.project_id',
            'group' => 'firebase',
            'value' => 'ase-fcm-test',
            'is_secret' => false,
        ]);

        AppConfig::create([
            'key' => 'firebase.service_account',
            'group' => 'firebase',
            'value' => $this->serviceAccountJson(),
            'is_secret' => true,
        ]);
    }

    public function test_user_registers_and_unregisters_push_devices(): void
    {
        [$user, $token] = $this->verifiedUser();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/notifications/devices', [
                'platform' => 'android',
                'token' => 'fcm-token-android-0000000000001',
                'name' => 'Phone',
            ])
            ->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/notifications/devices')
            ->assertOk()
            ->assertJsonPath('data.devices.0.platform', 'android')
            ->assertJsonPath('data.devices.0.name', 'Phone');

        // Re-registration updates rather than duplicates.
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/notifications/devices', [
                'platform' => 'ios',
                'token' => 'fcm-token-android-0000000000001',
            ])
            ->assertOk()
            ->assertJsonPath('data.platform', 'ios');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson('/api/v1/notifications/devices/fcm-token-android-0000000000001')
            ->assertOk();

        $this->assertSame(0, $user->pushDevices()->count());

        // Other users cannot delete this device token.
        [$other] = $this->verifiedUser('08031234568');
        $this->withHeaders($this->authHeaders($other->createToken('t')->plainTextToken))
            ->deleteJson('/api/v1/notifications/devices/fcm-token-android-0000000000001')
            ->assertStatus(404);
    }

    public function test_admin_test_push_requires_firebase_configuration(): void
    {
        [$user, $token] = $this->verifiedUser();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/notifications/devices', [
                'platform' => 'android',
                'token' => 'fcm-token-android-0000000000002',
            ])
            ->assertStatus(201);

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/push/test', [])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'FCM_NOT_CONFIGURED');
    }

    public function test_admin_test_push_sends_via_fcm_when_configured(): void
    {
        $this->configureFirebase();

        [$user, $token] = $this->verifiedUser();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/notifications/devices', [
                'platform' => 'ios',
                'token' => 'fcm-token-ios-00000000000000003',
            ])
            ->assertStatus(201);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response([
                'access_token' => 'fcm-test-token',
                'expires_in' => 3600,
                'token_type' => 'Bearer',
            ]),
            'fcm.googleapis.com/*' => Http::response([
                'name' => 'projects/ase-fcm-test/messages/msg-1',
            ]),
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/v1/admin/push/test', [])
            ->assertOk()
            ->assertJsonPath('data.message_id', 'projects/ase-fcm-test/messages/msg-1');

        Http::assertSent(fn ($request) => str_contains($request->url(), '/v1/projects/ase-fcm-test/messages:send')
            && $request->hasHeader('Authorization', 'Bearer fcm-test-token')
            && $request['message']['token'] === 'fcm-token-ios-00000000000000003');
    }

    public function test_push_device_panel_lists_devices(): void
    {
        $this->configureFirebase();

        [$user, $token] = $this->verifiedUser();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/notifications/devices', [
                'platform' => 'web',
                'token' => 'fcm-token-web-000000000000000004',
                'name' => 'Browser',
            ])
            ->assertStatus(201);

        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/push/devices')
            ->assertOk()
            ->assertJsonPath('data.firebase_configured', true)
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.devices.0.platform', 'web')
            ->assertJsonPath('data.devices.0.user', $user->name);
    }
}
