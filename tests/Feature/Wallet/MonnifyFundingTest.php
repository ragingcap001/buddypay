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
 * Wallet funding over the Monnify rail (one-time payment, bank transfer).
 * All Monnify HTTP traffic is faked.
 */
final class MonnifyFundingTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    private const SECRET = 'monnify-test-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ase.monnify.api_key' => 'MK_TEST_123',
            'ase.monnify.secret_key' => self::SECRET,
            'ase.monnify.contract_code' => '8389328412',
        ]);

        $this->fakeMonnify();
    }

    /**
     * Fake the Monnify API: login token + init-transaction + query.
     */
    private function fakeMonnify(string $paymentStatus = 'PAID'): void
    {
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();

            if (str_contains($url, '/api/v1/auth/login')) {
                return Http::response([
                    'requestSuccessful' => true,
                    'responseMessage' => 'success',
                    'responseCode' => '0',
                    'responseBody' => [
                        'accessToken' => 'test-access-token',
                        'expiresIn' => 3600,
                    ],
                ]);
            }

            if (str_contains($url, '/api/v1/merchant/transactions/init-transaction') && $request->method() === 'POST') {
                $body = json_decode($request->body(), true);

                return Http::response([
                    'requestSuccessful' => true,
                    'responseMessage' => 'success',
                    'responseCode' => '0',
                    'responseBody' => [
                        'transactionReference' => 'MNFY_TRX_1',
                        'paymentReference' => $body['paymentReference'] ?? '',
                        'enabledPaymentMethod' => ['ACCOUNT_TRANSFER', 'CARD'],
                        'checkoutUrl' => 'https://pay.monnify.com/checkout/MNFY_TRX_1',
                    ],
                ]);
            }

            if (str_contains($url, '/api/v2/merchant/transactions/query')) {
                return Http::response([
                    'requestSuccessful' => true,
                    'responseCode' => '0',
                    'responseBody' => [
                        'transactionReference' => 'MNFY_TRX_1',
                        'paymentStatus' => $paymentStatus,
                        'amountPaid' => 1000,
                        'currencyCode' => 'NGN',
                    ],
                ]);
            }

            return Http::response(['requestSuccessful' => true, 'responseCode' => '0', 'responseBody' => []]);
        });
    }

    private function signedMonnifyWebhook(array $payload): array
    {
        return [
            'payload' => $payload,
            'signature' => hash_hmac('sha512', json_encode($payload), self::SECRET),
        ];
    }

    public function test_funding_via_monnify_returns_checkout_and_enters_verifying(): void
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $reference = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'monnify-fund-1')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', [
                'amount' => 100000,
                'method' => 'bank_transfer',
                'provider' => 'monnify',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'VERIFYING')
            ->assertJsonPath('data.type', 'WALLET_FUNDING')
            ->assertJsonPath('data.provider', 'monnify')
            ->assertJsonPath('data.metadata.payment_details.payment_url', 'https://pay.monnify.com/checkout/MNFY_TRX_1')
            ->assertJsonPath('data.metadata.payment_details.account_number', '6000140770')
            ->json('data.reference');

        $this->assertNotNull($reference);

        // Wallet NOT credited until Monnify confirms the payment.
        $this->assertSame(0, (int) $user->wallet->fresh()->control_balance);

        // The platform reference was sent as Monnify's paymentReference,
        // with currencyCode and a numeric Naira amount.
        Http::assertSent(function (HttpRequest $r) use ($reference) {
            if (! str_contains($r->url(), '/api/v1/merchant/transactions/init-transaction')) {
                return false;
            }

            $body = json_decode($r->body(), true);

            return ($body['paymentReference'] ?? null) === $reference
                && ($body['currencyCode'] ?? null) === 'NGN'
                && ($body['amount'] ?? null) === 1000;
        });
    }

    public function test_monnify_webhook_confirms_funding(): void
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $reference = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'monnify-fund-2')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', [
                'amount' => 100000,
                'method' => 'bank_transfer',
                'provider' => 'monnify',
            ])
            ->assertOk()
            ->json('data.reference');

        $payload = [
            'eventType' => 'SUCCESSFUL_TRANSACTION',
            'eventData' => [
                'product' => ['reference' => $reference, 'type' => 'GENERAL'],
                'transactionReference' => 'MNFY_TRX_1',
                'paymentReference' => $reference,
                'amountPaid' => '1000.00',
                'totalPayable' => '1000.00',
                'paymentStatus' => 'PAID',
                'paymentMethod' => 'ACCOUNT_TRANSFER',
                'currency' => 'NGN',
            ],
        ];
        $signed = $this->signedMonnifyWebhook($payload);

        $this->postJson('/api/v1/webhooks/monnify', $signed['payload'], [
            'monnify-signature' => $signed['signature'],
        ])->assertOk()->assertJsonPath('data.status', 'PROCESSED');

        $this->assertSame('COMPLETED', Transaction::where('reference', $reference)->first()->fresh()->status);
        $this->assertSame(100000, (int) $user->wallet->fresh()->control_balance);
        $this->assertTrue(app(LedgerService::class)->integrityReport()['balanced']);
    }

    public function test_monnify_webhook_with_invalid_signature_is_rejected(): void
    {
        $payload = [
            'eventType' => 'SUCCESSFUL_TRANSACTION',
            'eventData' => ['paymentReference' => 'ASE_T_FAKE', 'paymentStatus' => 'PAID'],
        ];

        $this->postJson('/api/v1/webhooks/monnify', $payload, [
            'monnify-signature' => 'bogus',
        ])->assertStatus(401)->assertJsonPath('error.code', 'WEBHOOK_SIGNATURE_INVALID');
    }

    public function test_duplicate_monnify_webhook_has_no_double_effect(): void
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $reference = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'monnify-fund-3')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', [
                'amount' => 100000,
                'method' => 'bank_transfer',
                'provider' => 'monnify',
            ])
            ->assertOk()
            ->json('data.reference');

        $payload = [
            'eventType' => 'SUCCESSFUL_TRANSACTION',
            'eventData' => [
                'transactionReference' => 'MNFY_TRX_DUP',
                'paymentReference' => $reference,
                'paymentStatus' => 'PAID',
            ],
        ];
        $signed = $this->signedMonnifyWebhook($payload);
        $headers = ['monnify-signature' => $signed['signature']];

        $first = $this->postJson('/api/v1/webhooks/monnify', $payload, $headers);
        $second = $this->postJson('/api/v1/webhooks/monnify', $payload, $headers);

        $first->assertOk()->assertJsonPath('data.status', 'PROCESSED');
        $second->assertOk()->assertJsonPath('data.duplicate', true);

        $this->assertSame(100000, (int) $user->wallet->fresh()->control_balance);
        $this->assertSame(1, ProviderWebhook::where('provider_event_id', 'SUCCESSFUL_TRANSACTION|MNFY_TRX_DUP')->count());
    }

    public function test_webhook_with_mismatched_amount_is_not_settled(): void
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $reference = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'monnify-fund-mismatch')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', [
                'amount' => 100000,
                'method' => 'bank_transfer',
                'provider' => 'monnify',
            ])
            ->assertOk()
            ->json('data.reference');

        // Claims ₦5,000 was paid for a ₦1,000 funding — must NOT settle.
        $payload = [
            'eventType' => 'SUCCESSFUL_TRANSACTION',
            'eventData' => [
                'transactionReference' => 'MNFY_TRX_MISMATCH',
                'paymentReference' => $reference,
                'amountPaid' => '5000.00',
                'paymentStatus' => 'PAID',
            ],
        ];
        $signed = $this->signedMonnifyWebhook($payload);

        $this->postJson('/api/v1/webhooks/monnify', $signed['payload'], [
            'monnify-signature' => $signed['signature'],
        ])->assertOk();

        // Still verifying, wallet untouched (reconciliation will flag it).
        $this->assertSame('VERIFYING', Transaction::where('reference', $reference)->first()->fresh()->status);
        $this->assertSame(0, (int) $user->wallet->fresh()->control_balance);
    }

    public function test_unknown_monnify_event_types_are_ignored_not_failed(): void
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $reference = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'monnify-fund-unknown-evt')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', [
                'amount' => 100000,
                'method' => 'bank_transfer',
                'provider' => 'monnify',
            ])
            ->assertOk()
            ->json('data.reference');

        // e.g. a settlement notification must not fail the transaction.
        $payload = [
            'eventType' => 'SETTLEMENT_COMPLETED',
            'eventData' => ['transactionReference' => 'MNFY_TRX_SETTLE'],
        ];
        $signed = $this->signedMonnifyWebhook($payload);

        $this->postJson('/api/v1/webhooks/monnify', $signed['payload'], [
            'monnify-signature' => $signed['signature'],
        ])->assertOk()->assertJsonPath('data.status', 'RECEIVED');

        $this->assertSame('VERIFYING', Transaction::where('reference', $reference)->first()->fresh()->status);
    }

    public function test_verify_endpoint_confirms_monnify_transaction(): void
    {
        $this->fakeMonnify('PAID');

        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $reference = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'monnify-fund-4')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', [
                'amount' => 100000,
                'method' => 'bank_transfer',
                'provider' => 'monnify',
            ])
            ->assertOk()
            ->json('data.reference');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/v1/transactions/{$reference}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertSame(100000, (int) $user->wallet->fresh()->control_balance);
    }
}
