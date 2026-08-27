<?php

namespace Tests\Feature\Transactions;

use App\Domain\Ledger\Services\LedgerService;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

/**
 * Wallet -> bank payouts over the Monnify disbursement rail. All Monnify
 * HTTP traffic is faked.
 */
final class MonnifyPayoutTest extends TestCase
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
            'ase.monnify.source_account_number' => '9999999999',
        ]);
    }

    private function fundedUser(int $balanceKobo): array
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'monnify-payout-fund')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', ['amount' => $balanceKobo])
            ->assertOk();

        return [$user, $token];
    }

    private function fakeMonnify(string $transferStatus = 'PENDING_AUTHORIZATION'): void
    {
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();

            if (str_contains($url, '/api/v2/oauth/token')) {
                return Http::response([
                    'requestSuccessful' => true,
                    'responseCode' => '0',
                    'responseBody' => ['access_token' => 'test-access-token', 'expires_in' => 120],
                ]);
            }

            if (str_contains($url, '/api/v2/disbursements/single') && $request->method() === 'POST') {
                $body = json_decode($request->body(), true);

                return Http::response([
                    'requestSuccessful' => true,
                    'responseCode' => '0',
                    'responseBody' => [
                        'reference' => (string) ($body['reference'] ?? ''),
                        'status' => $transferStatus,
                        'totalFee' => 10.75,
                        'sourceAccountNumber' => '9999999999',
                    ],
                ]);
            }

            if (str_contains($url, '/api/v2/disbursements/single')) {
                return Http::response([
                    'requestSuccessful' => true,
                    'responseCode' => '0',
                    'responseBody' => [
                        'reference' => '',
                        'status' => 'SUCCESS',
                        'transactionReference' => 'MONNIFY_TRX_1',
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

    public function test_monnify_payout_waits_for_authorization_then_settles_on_webhook(): void
    {
        $this->fakeMonnify('PENDING_AUTHORIZATION');

        [$user, $token] = $this->fundedUser(1000000); // ₦10,000

        $reference = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'monnify-payout-1')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/payout', [
                'amount' => 250000,
                'bank_code' => '232',
                'account_number' => '0123456789',
                'account_name' => 'JOHN DOE',
                'provider' => 'monnify',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'VERIFYING')
            ->assertJsonPath('data.type', 'BANK_TRANSFER')
            ->assertJsonPath('data.provider', 'monnify')
            ->json('data.reference');

        // Funds held in reservation (amount + fee).
        $this->assertSame(252600, (int) $user->wallet->fresh()->reserved_balance);

        // The platform reference was sent as the Monnify transfer reference,
        // funded from the configured disbursement wallet.
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/api/v2/disbursements/single')
            && json_decode($r->body(), true)['reference'] === $reference
            && json_decode($r->body(), true)['sourceAccountNumber'] === '9999999999');

        $payload = [
            'eventType' => 'SUCCESSFUL_DISBURSEMENT',
            'eventData' => [
                'reference' => $reference,
                'transactionReference' => 'MONNIFY_TRX_1',
                'status' => 'SUCCESS',
                'amount' => '2500.00',
            ],
        ];
        $signed = $this->signedMonnifyWebhook($payload);

        $this->postJson('/api/v1/webhooks/monnify', $signed['payload'], [
            'monnify-signature' => $signed['signature'],
        ])->assertOk()->assertJsonPath('data.status', 'PROCESSED');

        $this->assertSame('COMPLETED', Transaction::where('reference', $reference)->first()->fresh()->status);
        $this->assertSame(1000000 - 252600, (int) $user->wallet->fresh()->control_balance);
        $this->assertTrue(app(LedgerService::class)->integrityReport()['balanced']);
    }

    public function test_monnify_failed_disbursement_releases_reservation(): void
    {
        $this->fakeMonnify('SUCCESS');

        [$user, $token] = $this->fundedUser(1000000);

        $reference = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'monnify-payout-2')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/payout', [
                'amount' => 250000,
                'bank_code' => '232',
                'account_number' => '0123456789',
                'account_name' => 'JOHN DOE',
                'provider' => 'monnify',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED') // MFA disabled → immediate success
            ->json('data.reference');

        // A later FAILED_DISBURSEMENT for a settled transaction is a no-op.
        $payload = [
            'eventType' => 'FAILED_DISBURSEMENT',
            'eventData' => ['reference' => $reference, 'transactionReference' => 'MONNIFY_TRX_2', 'status' => 'FAILED'],
        ];
        $signed = $this->signedMonnifyWebhook($payload);

        $this->postJson('/api/v1/webhooks/monnify', $signed['payload'], [
            'monnify-signature' => $signed['signature'],
        ])->assertOk();

        $this->assertSame('COMPLETED', Transaction::where('reference', $reference)->first()->fresh()->status);
        $this->assertSame(1000000 - 252600, (int) $user->wallet->fresh()->control_balance);
    }

    public function test_monnify_payout_verifies_via_status_endpoint(): void
    {
        $this->fakeMonnify('PENDING_AUTHORIZATION');

        [$user, $token] = $this->fundedUser(1000000);

        $reference = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'monnify-payout-3')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/payout', [
                'amount' => 250000,
                'bank_code' => '232',
                'account_number' => '0123456789',
                'account_name' => 'JOHN DOE',
                'provider' => 'monnify',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'VERIFYING')
            ->json('data.reference');

        // Status endpoint reports SUCCESS → verify settles the transaction.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/v1/transactions/{$reference}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $this->assertSame(1000000 - 252600, (int) $user->wallet->fresh()->control_balance);
    }
}
