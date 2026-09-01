<?php

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Services\FcmService;
use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class FcmTest extends TestCase
{
    use RefreshDatabase;

    private function configureFirebase(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);

        AppConfig::create([
            'key' => 'firebase.project_id',
            'group' => 'firebase',
            'value' => 'ase-fcm-test',
            'is_secret' => false,
        ]);

        AppConfig::create([
            'key' => 'firebase.service_account',
            'group' => 'firebase',
            'value' => (string) json_encode([
                'client_email' => 'fcm-test@ase.iam.gserviceaccount.com',
                'private_key' => $pem,
                'project_id' => 'ase-fcm-test',
            ]),
            'is_secret' => true,
        ]);
    }

    public function test_not_configured_without_firebase_values(): void
    {
        $this->assertFalse(app(FcmService::class)->isConfigured());
    }

    public function test_access_token_exchange_sends_a_valid_jwt_assertion(): void
    {
        $this->configureFirebase();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'abc', 'expires_in' => 3600]),
        ]);

        $token = app(FcmService::class)->accessToken();

        $this->assertSame('abc', $token);

        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return false;
            }

            $assertion = $request['assertion'];
            $parts = explode('.', (string) $assertion);

            if (count($parts) !== 3) {
                return false;
            }

            $header = json_decode((string) base64_decode(strtr($parts[0], '-_', '+/')), true);
            $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

            return ($header['alg'] ?? '') === 'RS256'
                && ($payload['iss'] ?? '') === 'fcm-test@ase.iam.gserviceaccount.com'
                && ($payload['scope'] ?? '') === 'https://www.googleapis.com/auth/firebase.messaging';
        });
    }

    public function test_send_delivers_to_fcm_and_reports_message_id(): void
    {
        $this->configureFirebase();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'abc', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/p/messages/m-1']),
        ]);

        $result = app(FcmService::class)->send('token-1', 'Hello', 'World', ['type' => 'test']);

        $this->assertTrue($result['ok']);
        $this->assertSame('projects/p/messages/m-1', $result['message_id']);
    }

    public function test_send_reports_soft_failure_for_rejected_tokens(): void
    {
        $this->configureFirebase();

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'abc', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response([
                'error' => ['code' => 404, 'message' => 'Requested entity was not found.'],
            ], 404),
        ]);

        $result = app(FcmService::class)->send('dead-token', 'Hello', 'World');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not found', (string) $result['error']);
    }

    public function test_send_to_user_fans_out_to_all_active_devices(): void
    {
        $this->configureFirebase();

        $user = User::factory()->create();
        $user->pushDevices()->createMany([
            ['platform' => 'android', 'token' => 'token-a', 'active' => true],
            ['platform' => 'ios', 'token' => 'token-b', 'active' => true],
            ['platform' => 'web', 'token' => 'token-c', 'active' => false],
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'abc', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response(['name' => 'projects/p/messages/m']),
        ]);

        $result = app(FcmService::class)->sendToUser($user, 'T', 'B');

        $this->assertSame(2, $result['sent']);
        $this->assertSame(0, $result['failed']);
        $this->assertNotNull($user->pushDevices()->where('token', 'token-a')->first()->last_used_at);
    }

    public function test_permanently_dead_tokens_are_deactivated(): void
    {
        $this->configureFirebase();

        $user = User::factory()->create();
        $user->pushDevices()->createMany([
            ['platform' => 'ios', 'token' => 'live-token-00000000000000002', 'active' => true],
            ['platform' => 'android', 'token' => 'dead-token-00000000000000001', 'active' => true],
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'abc', 'expires_in' => 3600]),
            // First device (live) delivers; second (dead) is gone from FCM.
            'fcm.googleapis.com/*' => Http::sequence()
                ->push(['name' => 'projects/p/messages/m-1'])
                ->push(['error' => ['code' => 404, 'message' => 'Requested entity was not found.']], 404),
        ]);

        $result = app(FcmService::class)->sendToUser($user, 'T', 'B');

        $this->assertSame(1, $result['sent']);
        $this->assertSame(1, $result['failed']);
        $this->assertFalse((bool) $user->pushDevices()->where('token', 'dead-token-00000000000000001')->first()->active);
        $this->assertTrue((bool) $user->pushDevices()->where('token', 'live-token-00000000000000002')->first()->active);
    }

    public function test_notification_flow_delivers_push_and_logs_failure_without_breaking(): void
    {
        $this->configureFirebase();

        $user = User::factory()->create();
        $user->pushDevices()->create([
            'platform' => 'web', 'token' => 'dead-token-00000000000000003', 'active' => true,
        ]);

        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'abc', 'expires_in' => 3600]),
            'fcm.googleapis.com/*' => Http::response([
                'error' => ['code' => 404, 'message' => 'Requested entity was not found.'],
            ], 404),
        ]);

        // Must not throw — the financial flow continues; the PUSH delivery
        // row records the failure.
        $notification = app(\App\Domain\Notifications\Services\NotificationService::class)
            ->send($user->id, 'transaction.completed', 'Title', 'Body');

        $this->assertSame('SENT', $notification->fresh()->status);
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $notification->id,
            'channel' => 'PUSH',
            'status' => 'FAILED',
        ]);
        $this->assertDatabaseHas('notification_deliveries', [
            'notification_id' => $notification->id,
            'channel' => 'LOG',
            'status' => 'SENT',
        ]);
    }
}
