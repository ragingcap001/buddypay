<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class HealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_reports_healthy(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertOk()
            ->assertJsonPath('status', 'healthy')
            ->assertJsonPath('checks.database.status', 'ok')
            ->assertJsonStructure(['status', 'checks', 'timestamp']);
    }

    public function test_health_endpoint_reports_unhealthy_when_database_down(): void
    {
        config(['database.connections.pgsql.database' => 'definitely-not-a-real-database']);
        \Illuminate\Support\Facades\DB::purge('pgsql');

        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(503)
            ->assertJsonPath('status', 'unhealthy')
            ->assertJsonPath('checks.database.status', 'error');
    }
}
