<?php

namespace Tests\Feature\Webhooks;

use App\Models\ProviderWebhook;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

final class WebhookTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    private function signedWebhook(array $payload): array
    {
        $secret = (string) config('ase.webhook_secrets.mock');

        return [
            'payload' => $payload,
            'signature' => hash_hmac('sha256', json_encode($payload), $secret),
        ];
    }

    private function makeVerifyingFundingTransaction(int $amountKobo): array
    {
        config(['ase.mock.funding_mode' => 'timeout']);

        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'webhook-fund-1')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', ['amount' => $amountKobo]);

        $response->assertOk()->assertJsonPath('data.status', 'VERIFYING');

        return [$user, $response->json('data.reference')];
    }

    public function test_invalid_signature_is_rejected(): void
    {
        $payload = ['event_type' => 'payment.success', 'event_id' => 'evt-1', 'reference' => 'ASE_T_1'];

        $this->postJson('/api/v1/webhooks/mock', $payload, [
            'X-Webhook-Signature' => 'not-a-real-signature',
        ])->assertStatus(401)
            ->assertJsonPath('error.code', 'WEBHOOK_SIGNATURE_INVALID');
    }

    public function test_unknown_provider_is_rejected(): void
    {
        $secret = (string) config('ase.webhook_secrets.mock');
        $payload = ['event_type' => 'payment.success', 'event_id' => 'evt-2'];

        $this->postJson('/api/v1/webhooks/does-not-exist', $payload, [
            'X-Webhook-Signature' => hash_hmac('sha256', json_encode($payload), $secret),
        ])->assertStatus(404);
    }

    public function test_webhook_confirms_ambiguous_funding(): void
    {
        [$user, $reference] = $this->makeVerifyingFundingTransaction(100000);

        // No credit while ambiguous.
        $this->assertSame(0, (int) $user->wallet->fresh()->control_balance);

        $payload = [
            'event_type' => 'payment.success',
            'event_id' => 'evt-3',
            'reference' => $reference,
            'provider_reference' => 'MOCKPAY_123',
        ];

        $signed = $this->signedWebhook($payload);

        $response = $this->postJson('/api/v1/webhooks/mock', $signed['payload'], [
            'X-Webhook-Signature' => $signed['signature'],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'PROCESSED');

        $transaction = Transaction::where('reference', $reference)->fresh();
        $this->assertSame('COMPLETED', $transaction->status);
        $this->assertSame(100000, (int) $user->wallet->fresh()->control_balance);

        $this->assertDatabaseHas('provider_webhooks', [
            'provider_event_id' => 'evt-3',
            'status' => 'PROCESSED',
        ]);

        $this->assertTrue(app(\App\Domain\Ledger\Services\LedgerService::class)->integrityReport()['balanced']);
    }

    public function test_duplicate_webhook_delivery_has_no_double_effect(): void
    {
        [$user, $reference] = $this->makeVerifyingFundingTransaction(100000);

        $payload = [
            'event_type' => 'payment.success',
            'event_id' => 'evt-dup',
            'reference' => $reference,
            'provider_reference' => 'MOCKPAY_999',
        ];
        $signed = $this->signedWebhook($payload);
        $headers = ['X-Webhook-Signature' => $signed['signature']];

        $first = $this->postJson('/api/v1/webhooks/mock', $payload, $headers);
        $second = $this->postJson('/api/v1/webhooks/mock', $payload, $headers);

        $first->assertOk()->assertJsonPath('data.status', 'PROCESSED');
        $second->assertOk()->assertJsonPath('data.duplicate', true);

        // Wallet credited exactly once; ledger still balanced.
        $this->assertSame(100000, (int) $user->wallet->fresh()->control_balance);
        $this->assertSame(1, ProviderWebhook::where('provider_event_id', 'evt-dup')->count());
        $this->assertTrue(app(\App\Domain\Ledger\Services\LedgerService::class)->integrityReport()['balanced']);
    }

    public function test_webhook_for_already_settled_transaction_is_a_noop(): void
    {
        config(['ase.mock.funding_mode' => 'success']);

        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'webhook-fund-2')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', ['amount' => 50000]);

        $reference = $response->assertOk()->assertJsonPath('data.status', 'COMPLETED')->json('data.reference');

        $payload = [
            'event_type' => 'payment.success',
            'event_id' => 'evt-late',
            'reference' => $reference,
            'provider_reference' => 'MOCKPAY_LATE',
        ];
        $signed = $this->signedWebhook($payload);

        $this->postJson('/api/v1/webhooks/mock', $signed['payload'], [
            'X-Webhook-Signature' => $signed['signature'],
        ])->assertOk();

        // Still credited exactly once.
        $this->assertSame(50000, (int) $user->wallet->fresh()->control_balance);
    }
}
