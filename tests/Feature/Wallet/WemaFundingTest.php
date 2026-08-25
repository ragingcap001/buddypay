<?php

namespace Tests\Feature\Wallet;

use App\Domain\Ledger\Services\LedgerService;
use App\Models\ProviderWebhook;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

/**
 * Wallet funding over the Wema (ALAT) payment-request rail (bank transfer
 * IN). All Wema HTTP traffic is faked.
 */
final class WemaFundingTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    private const WEMA_SECRET = 'wema-test-secret';

    private function fundUser(int $amountKobo, string $idempotencyKey): array
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', [
                'amount' => $amountKobo,
                'method' => 'bank_transfer',
                'provider' => 'wema',
            ]);

        return [$user, $token, $response];
    }

    /**
     * Fake the Wema ALAT API: payment-request creation + status.
     */
    private function fakeWema(string $status = 'COMPLETED', string $virtualAccount = '0310000123'): void
    {
        Http::fake(function (HttpRequest $request): \Illuminate\Http\Client\Response {
            $url = $request->url();

            if (str_contains($url, '/payments/v1/paymentrequests') && $request->method() === 'POST') {
                $body = json_decode($request->body(), true);

                return Http::response([
                    'result' => [
                        'reference' => (string) ($body['reference'] ?? ''),
                        'status' => 'PENDING',
                        'virtualAccount' => $virtualAccount,
                        'virtualBank' => 'Wema Bank',
                    ],
                    'errorMessage' => null,
                    'errorMessages' => [],
                    'hasError' => false,
                    'timeGenerated' => now()->toIso8601String(),
                ]);
            }

            if (str_contains($url, '/payments/v1/paymentrequests')) {
                return Http::response([
                    'result' => [
                        'reference' => '',
                        'status' => $status,
                        'amount' => 100000,
                    ],
                    'errorMessage' => null,
                    'errorMessages' => [],
                    'hasError' => false,
                    'timeGenerated' => now()->toIso8601String(),
                ]);
            }

            return Http::response(['result' => [], 'hasError' => false]);
        });
    }

    public function test_funding_via_wema_returns_virtual_account_and_enters_verifying(): void
    {
        $this->fakeWema();

        [$user, , $response] = $this->fundUser(100000, 'wema-fund-1');

        $reference = $response->assertOk()
            ->assertJsonPath('data.status', 'VERIFYING')
            ->assertJsonPath('data.type', 'WALLET_FUNDING')
            ->assertJsonPath('data.provider', 'wema')
            ->assertJsonPath('data.metadata.payment_details.account_number', '0310000123')
            ->assertJsonPath('data.metadata.payment_details.bank', 'Wema Bank')
            ->json('data.reference');

        $this->assertNotNull($reference);

        // Wallet is NOT credited until Wema confirms the transfer.
        $this->assertSame(0, (int) $user->wallet->fresh()->control_balance);
    }

    public function test_wema_webhook_confirms_funding(): void
    {
        $this->fakeWema();
        config(['ase.webhook_secrets.wema' => self::WEMA_SECRET]);

        [$user, , $response] = $this->fundUser(100000, 'wema-fund-2');
        $reference = $response->json('data.reference');

        $payload = [
            'title' => 'Payment Request Notification',
            'message' => 'Payment confirmed',
            'data' => [
                'status' => 'SUCCESSFUL',
                'message' => 'Payment confirmed',
                'narration' => 'Wallet funding',
                'transactionReference' => $reference,
                'platformTransactionReference' => 'WEMA_TRX_1',
            ],
        ];
        $signature = hash_hmac('sha256', json_encode($payload), self::WEMA_SECRET);

        $this->postJson('/api/v1/webhooks/wema', $payload, [
            'X-Webhook-Signature' => $signature,
        ])->assertOk()->assertJsonPath('data.status', 'PROCESSED');

        $transaction = Transaction::where('reference', $reference)->fresh();
        $this->assertSame('COMPLETED', $transaction->status);
        $this->assertSame(100000, (int) $user->wallet->fresh()->control_balance);
        $this->assertTrue(app(LedgerService::class)->integrityReport()['balanced']);
    }

    public function test_wema_webhook_with_invalid_signature_is_rejected(): void
    {
        $this->fakeWema();
        config(['ase.webhook_secrets.wema' => self::WEMA_SECRET]);

        $this->fundUser(100000, 'wema-fund-3');

        $payload = [
            'title' => 'Payment Request Notification',
            'message' => 'Payment confirmed',
            'data' => [
                'status' => 'SUCCESSFUL',
                'transactionReference' => 'ASE_T_FAKE',
            ],
        ];

        $this->postJson('/api/v1/webhooks/wema', $payload, [
            'X-Webhook-Signature' => 'not-a-real-signature',
        ])->assertStatus(401)->assertJsonPath('error.code', 'WEBHOOK_SIGNATURE_INVALID');
    }

    public function test_duplicate_wema_webhook_has_no_double_effect(): void
    {
        $this->fakeWema();
        config(['ase.webhook_secrets.wema' => self::WEMA_SECRET]);

        [$user, , $response] = $this->fundUser(100000, 'wema-fund-4');
        $reference = $response->json('data.reference');

        $payload = [
            'title' => 'Payment Request Notification',
            'message' => 'Payment confirmed',
            'data' => [
                'status' => 'SUCCESSFUL',
                'transactionReference' => $reference,
                'platformTransactionReference' => 'WEMA_TRX_DUP',
            ],
        ];
        $headers = ['X-Webhook-Signature' => hash_hmac('sha256', json_encode($payload), self::WEMA_SECRET)];

        $first = $this->postJson('/api/v1/webhooks/wema', $payload, $headers);
        $second = $this->postJson('/api/v1/webhooks/wema', $payload, $headers);

        $first->assertOk()->assertJsonPath('data.status', 'PROCESSED');
        $second->assertOk()->assertJsonPath('data.duplicate', true);

        $this->assertSame(100000, (int) $user->wallet->fresh()->control_balance);
        $this->assertSame(1, ProviderWebhook::where('provider_event_id', 'wema-callback|'.$reference.'|SUCCESSFUL')->count());
    }

    public function test_verify_endpoint_confirms_wema_payment_request(): void
    {
        $this->fakeWema('COMPLETED');
        config(['ase.webhook_secrets.wema' => self::WEMA_SECRET]);

        [$user, $token, $response] = $this->fundUser(100000, 'wema-fund-5');
        $reference = $response->json('data.reference');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/v1/transactions/{$reference}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertSame(100000, (int) $user->wallet->fresh()->control_balance);
    }
}
