<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class ApiEnvelopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthenticated_api_request_uses_error_envelope_with_request_id(): void
    {
        $response = $this->getJson('/api/v1/wallet/balance');

        $response->assertStatus(401)
            ->assertJsonStructure(['success', 'error' => ['code', 'message'], 'request_id'])
            ->assertJsonPath('success', false);

        $requestId = $response->json('request_id');
        $this->assertNotEmpty($requestId);
        $this->assertSame($requestId, $response->headers->get('X-Request-Id'));
    }

    public function test_validation_errors_use_the_error_envelope(): void
    {
        $response = $this->postJson('/api/v1/auth/register', [
            'name' => 'X',
            'phone' => '12345',
            'password' => 'short',
        ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error.code', 'VALIDATION_ERROR')
            ->assertJsonStructure(['error' => ['code', 'message', 'errors']]);
    }
}
