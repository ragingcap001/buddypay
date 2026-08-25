<?php

namespace Tests\Feature\Reconciliation;

use App\Domain\Reconciliation\Services\ReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

final class ReconciliationTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    public function test_successful_payment_reconciles_as_matched(): void
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);
        $user->wallet->update(['control_balance' => 500000]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'recon-1')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/bills/pay', [
                'type' => 'AIRTIME',
                'amount' => 100000,
                'phone' => '08039990001',
            ]);

        $reference = $response->assertOk()->assertJsonPath('data.status', 'COMPLETED')->json('data.reference');
        $this->assertNotNull($reference);

        $batch = app(ReconciliationService::class)->runBatch('mock', now()->startOfDay(), now()->endOfDay());

        $batch->refresh();

        $this->assertSame('COMPLETED', $batch->status);
        $this->assertGreaterThanOrEqual(1, (int) $batch->matched);
        $this->assertSame(0, (int) $batch->exceptions);

        $this->assertDatabaseHas('reconciliation_items', [
            'batch_id' => $batch->id,
            'reference' => $reference,
            'status' => 'MATCHED',
        ]);
    }

    public function test_missing_provider_record_is_flagged(): void
    {
        // A completed transaction whose provider attempt was deleted is
        // treated as MISSING_PROVIDER_RECORD.
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);
        $user->wallet->update(['control_balance' => 500000]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'recon-2')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/bills/pay', [
                'type' => 'AIRTIME',
                'amount' => 100000,
                'phone' => '08039990001',
            ]);

        $reference = $response->assertOk()->json('data.reference');

        \App\Models\ProviderAttempt::where('transaction_id', \App\Models\Transaction::where('reference', $reference)->value('id'))->delete();

        $batch = app(ReconciliationService::class)->runBatch('mock', now()->startOfDay(), now()->endOfDay());
        $batch->refresh();

        $this->assertSame('COMPLETED', $batch->status);
        $this->assertSame(0, (int) $batch->matched);
        $this->assertSame(1, (int) $batch->exceptions);

        $this->assertDatabaseHas('reconciliation_items', [
            'batch_id' => $batch->id,
            'reference' => $reference,
            'status' => 'MISSING_PROVIDER_RECORD',
        ]);
    }
}
