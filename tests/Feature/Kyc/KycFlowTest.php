<?php

namespace Tests\Feature\Kyc;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

final class KycFlowTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    public function test_profile_starts_unverified(): void
    {
        [, $token] = $this->verifiedUser();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/kyc')
            ->assertOk()
            ->assertJsonPath('data.tier', 0)
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonStructure(['data' => ['status', 'tier', 'limits']]);
    }

    public function test_bvn_verification_promotes_to_basic_tier(): void
    {
        [, $token] = $this->verifiedUser();

        // Odd last digit => verified by the mock provider.
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/kyc/bvn', ['bvn' => '22222222223'])
            ->assertOk()
            ->assertJsonPath('data.status', 'VERIFIED')
            ->assertJsonPath('data.tier', 1);

        // Limits are now the tier-1 limits (higher than tier 0).
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/kyc')
            ->assertOk()
            ->assertJsonPath('data.limits.per_transaction', 50000000);
    }

    public function test_failed_bvn_keeps_user_unverified(): void
    {
        [, $token] = $this->verifiedUser();

        // Even last digit => rejected by the mock provider.
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/kyc/bvn', ['bvn' => '22222222222'])
            ->assertOk()
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.tier', 0);
    }

    public function test_invalid_bvn_is_rejected_by_validation(): void
    {
        [, $token] = $this->verifiedUser();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/v1/kyc/bvn', ['bvn' => '123'])
            ->assertStatus(422);
    }

    public function test_document_upload(): void
    {
        [, $token] = $this->verifiedUser();

        $response = $this->withHeaders($this->authHeaders($token))
            ->post('/api/v1/kyc/documents', [
                'document' => \Illuminate\Http\UploadedFile::fake()->create('passport.jpg', 100, 'image/jpeg'),
                'type' => 'IDENTITY_FRONT',
            ]);

        $response->assertCreated()
            ->assertJsonPath('data.status', 'PENDING');

        $this->assertDatabaseHas('kyc_documents', ['type' => 'IDENTITY_FRONT']);
    }
}
