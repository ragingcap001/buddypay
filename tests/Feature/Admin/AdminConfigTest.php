<?php

namespace Tests\Feature\Admin;

use App\Domain\Config\Services\AppConfigService;
use App\Models\AppConfig;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Tests\TestCase;

final class AdminConfigTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The admin API is session + CSRF protected (same-origin dashboard);
        // these tests exercise the auth/role/logic layer directly.
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    private function admin(string $phone = '08030000001'): User
    {
        return User::factory()->create([
            'phone' => $phone,
            'role' => User::ROLE_ADMIN,
            'phone_verified_at' => now(),
        ]);
    }

    public function test_guest_cannot_access_admin_config(): void
    {
        $this->getJson('/api/v1/admin/config')->assertStatus(401);
    }

    public function test_non_admin_cannot_access_admin_config(): void
    {
        $user = User::factory()->create(['phone_verified_at' => now()]);

        $this->actingAs($user)->getJson('/api/v1/admin/config')->assertStatus(403);
        $this->actingAs($user)
            ->putJson('/api/v1/admin/config', ['group' => 'wema', 'values' => ['api_key' => 'x']])
            ->assertStatus(403);
    }

    public function test_admin_can_list_effective_config_with_secrets_masked(): void
    {
        config(['ase.wema.api_key' => 'wema-env-key']);

        $response = $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/config')
            ->assertOk()
            ->assertJsonPath('data.wema.api_key.env', 'WEMA_API_KEY')
            ->assertJsonPath('data.wema.base_url.value', 'https://wema-alatdev-apimgt.developer.azure-api.net')
            ->json('data');

        // Secrets are never returned in full — `value` is null, only the
        // masked form is exposed.
        $this->assertNull($response['wema']['api_key']['value']);
        $this->assertSame('••••-key', $response['wema']['api_key']['masked']);
        $this->assertSame(false, $response['wema']['api_key']['overridden']);

        // Non-secret values ARE returned in full (the form pre-fills them).
        $this->assertSame(
            'https://wema-alatdev-apimgt.developer.azure-api.net',
            $response['wema']['base_url']['value'],
        );

        // All expected groups are present.
        foreach (['wema', 'monnify', 'firebase', 'apple', 'google'] as $group) {
            $this->assertArrayHasKey($group, $response);
        }
    }

    public function test_admin_can_override_a_value_and_it_is_encrypted_at_rest(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/config', [
                'group' => 'wema',
                'values' => ['api_key' => 'dashboard-key-12345'],
            ])
            ->assertOk()
            ->assertJsonPath('data.applied.0', 'wema.api_key');

        $row = AppConfig::where('key', 'wema.api_key')->first();
        $this->assertNotNull($row);
        $this->assertSame($admin->id, (int) $row->updated_by);

        // Encrypted at rest: the raw DB column must not contain the value.
        $raw = \Illuminate\Support\Facades\DB::table('app_config')
            ->where('key', 'wema.api_key')->value('value');
        $this->assertStringNotContainsString('dashboard-key-12345', (string) $raw);

        // Resolved at runtime through the service.
        $this->assertSame(
            'dashboard-key-12345',
            app(AppConfigService::class)->get('wema', 'api_key'),
        );

        // And the Wema client now sees the dashboard value.
        $client = new \App\Infrastructure\Providers\Wema\WemaClient(app(AppConfigService::class));
        $this->assertSame('dashboard-key-12345', (new \ReflectionMethod($client, 'apiKey'))->invoke($client));
    }

    public function test_config_changes_are_audited_without_values(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/config', [
                'group' => 'monnify',
                'values' => ['api_key' => 'new-secret-value', 'contract_code' => '1234567890'],
            ])
            ->assertOk();

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'config.updated',
            'actor_id' => $admin->id,
        ]);

        $log = \App\Models\AuditLog::where('action', 'config.updated')->latest('id')->first();
        $this->assertContains('api_key', $log->metadata['keys']);
        // The secret value itself must not reach the audit trail.
        $this->assertStringNotContainsString('new-secret-value', (string) json_encode($log->metadata));
    }

    public function test_clearing_a_value_falls_back_to_env(): void
    {
        config(['ase.wema.api_key' => 'env-key-fallback']);

        $admin = $this->admin();

        $this->actingAs($admin)->putJson('/api/v1/admin/config', [
            'group' => 'wema',
            'values' => ['api_key' => 'temporary-override'],
        ])->assertOk();

        $this->assertSame('temporary-override', app(AppConfigService::class)->get('wema', 'api_key'));

        $this->actingAs($admin)->putJson('/api/v1/admin/config', [
            'group' => 'wema',
            'values' => ['api_key' => null],
        ])->assertOk();

        $this->assertSame('env-key-fallback', app(AppConfigService::class)->get('wema', 'api_key'));
    }

    public function test_unknown_keys_and_groups_are_ignored(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/config', ['group' => 'wema', 'values' => ['not_a_key' => 'x']])
            ->assertOk()
            ->assertJsonPath('data.applied', []);

        $this->actingAs($admin)
            ->putJson('/api/v1/admin/config', ['group' => 'nope', 'values' => ['a' => 'b']])
            ->assertStatus(422);

        $this->assertSame(0, AppConfig::count());
    }

    public function test_provider_panel_reports_status_and_attempts(): void
    {
        $this->seed(\Database\Seeders\ProviderSeeder::class);

        $provider = \App\Models\Provider::where('name', 'wema')->first();

        \App\Models\ProviderAttempt::create([
            'provider_id' => $provider->id,
            'type' => 'PAYOUT',
            'status' => 'SUCCESS',
            'duration_ms' => 12,
        ]);

        $this->actingAs($this->admin())
            ->getJson('/api/v1/admin/providers')
            ->assertOk()
            ->assertJsonPath('data.providers.0.name', 'monnify')
            ->assertJsonPath('data.providers.1.circuit', 'CLOSED');
    }
}
