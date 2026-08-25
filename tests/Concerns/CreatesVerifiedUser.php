<?php

namespace Tests\Concerns;

use App\Models\KycProfile;
use App\Models\User;
use App\Models\Wallet;

trait CreatesVerifiedUser
{
    /**
     * Create a fully set-up verified user (phone verified, wallet, KYC
     * profile) plus a Sanctum token for API calls.
     *
     * @return array{0: User, 1: string}
     */
    protected function verifiedUser(string $phone = '08031234567'): array
    {
        // Financial base data (system ledger accounts, mock provider, bill
        // catalog). Seeders are idempotent (firstOrCreate).
        $this->seed([
            \Database\Seeders\SystemAccountSeeder::class,
            \Database\Seeders\BillCatalogSeeder::class,
        ]);

        $user = User::factory()->create([
            'phone' => $phone,
            'phone_verified_at' => now(),
        ]);

        Wallet::create([
            'user_id' => $user->id,
            'currency' => 'NGN',
            'control_balance' => 0,
            'reserved_balance' => 0,
        ]);

        KycProfile::create([
            'user_id' => $user->id,
            'status' => 'PENDING',
            'tier' => 0,
        ]);

        return [$user, $user->createToken('test')->plainTextToken];
    }

    /**
     * @return array<string, string>
     */
    protected function authHeaders(string $token): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer '.$token,
        ];
    }
}
