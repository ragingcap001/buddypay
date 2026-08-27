<?php

namespace Tests\Feature\Webhooks;

use App\Domain\Ledger\Services\LedgerService;
use App\Models\ProviderWebhook;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

/**
 * Kuda `Bill.Transaction` webhook: authenticates via the `username` /
 * Base64(`password`) headers and settles the bill through BILL_TSQ.
 */
final class KudaWebhookTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    private const WH_USER = 'kuda-wh-user';
    private const WH_PASS = 'kuda-wh-pass';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ase.kuda.base_url' => 'https://kuda-openapi-uat.kudabank.com/v2.1',
            'ase.kuda.api_key' => 'kuda-test-key',
            'ase.kuda.email' => 'business@example.com',
            'ase.kuda.webhook_username' => self::WH_USER,
            'ase.kuda.webhook_password' => self::WH_PASS,
        ]);
    }

    private function fakeJwt(int $ttl = 3600): string
    {
        $b64 = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');

        return $b64('{"alg":"HS256","typ":"JWT"}')
            .'.'.$b64((string) json_encode(['exp' => time() + $ttl]))
           .'.signature';
    }

    /**
     * Create a VERIFYING Kuda airtime bill and fake the follow-up BILL_TSQ.
     *
     * @return array{0: \App\Models\User, 1: string, 2: string} user, platform ref, kuda request ref
     */
    private function makeVerifyingKudaBill(): array
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'GetToken')) {
                return Http::response($this->fakeJwt());
            }

            $body = json_decode($request->body(), true);

            return match ((string) ($body['serviceType'] ?? '')) {
                'ADMIN_PURCHASE_BILL' => Http::response([
                    'responseCode' => 'K00',
                    'BillResponseReference' => 'mtn77777',
                    'message' => 'Request received',
                ]),
                'BILL_TSQ' => Http::response([
                    'transactionStatus' => 3,
                    'finalStatus' => 'Completed',
                    'billResponseReference' => 'mtn77777',
                ]),
                default => Http::response(['responseCode' => 'K00']),
            };
        });

        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'kuda-wh-fund')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', ['amount' => 500000])
            ->assertOk();

        $reference = $this->withHeaders(array_merge($this->authHeaders($token), [
            'Idempotency-Key' => 'kuda-wh-bill-1',
            'X-Transaction-Pin' => '1234',
        ]))
            ->postJson('/api/v1/bills/pay', [
                'type' => 'AIRTIME',
                'amount' => 100000,
                'phone' => '08031234567',
                'provider' => 'kuda',
                'biller' => 'KD-VTU-MTNNG',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'VERIFYING')
            ->json('data.reference');

        $txn = Transaction::where('reference', $reference)->fresh();

        return [$user, $reference, (string) $txn->metadata['kuda_request_ref']];
    }

    public function test_bill_webhook_triggers_tsq_and_settles(): void
    {
        [$user, $reference, $kudaRequestRef] = $this->makeVerifyingKudaBill();

        $payload = [
            'eventType' => 'Bill.Transaction',
            'BillRequestRef' => $kudaRequestRef,
            'BillResponseReference' => 'mtn77777',
            'BillerName' => 'MTN NG VTU',
            'KudaAccountNumber' => '2000000000',
            'TransactionAmount' => '1000',
            'CustomerIdentifier' => '08031234567',
            'BillType' => 'airtime',
        ];

        $response = $this->postJson('/api/v1/webhooks/kuda', $payload, [
            'username' => self::WH_USER,
            'password' => base64_encode(self::WH_PASS),
        ]);

        $response->assertOk()->assertJsonPath('data.status', 'PROCESSED');

        $this->assertSame('COMPLETED', Transaction::where('reference', $reference)->fresh()->status);
        $this->assertSame(500000 - 100500, (int) $user->wallet->fresh()->control_balance);
        $this->assertSame(0, (int) $user->wallet->fresh()->reserved_balance);
        $this->assertTrue(app(LedgerService::class)->integrityReport()['balanced']);
    }

    public function test_webhook_with_invalid_credentials_is_rejected(): void
    {
        [, $reference] = $this->makeVerifyingKudaBill();

        $payload = [
            'eventType' => 'Bill.Transaction',
            'BillRequestRef' => 'KB000',
            'BillResponseReference' => 'mtn77777',
        ];

        $this->postJson('/api/v1/webhooks/kuda', $payload, [
            'username' => self::WH_USER,
            'password' => base64_encode('wrong-password'),
        ])->assertStatus(401)->assertJsonPath('error.code', 'WEBHOOK_SIGNATURE_INVALID');

        $this->postJson('/api/v1/webhooks/kuda', $payload, [
            'username' => 'someone-else',
            'password' => base64_encode(self::WH_PASS),
        ])->assertStatus(401);

        // Still verifying.
        $this->assertSame('VERIFYING', Transaction::where('reference', $reference)->fresh()->status);
    }

    public function test_duplicate_bill_webhook_has_no_double_effect(): void
    {
        [$user, $reference, $kudaRequestRef] = $this->makeVerifyingKudaBill();

        $payload = [
            'eventType' => 'Bill.Transaction',
            'BillRequestRef' => $kudaRequestRef,
            'BillResponseReference' => 'mtn77777',
        ];
        $headers = [
            'username' => self::WH_USER,
            'password' => base64_encode(self::WH_PASS),
        ];

        $first = $this->postJson('/api/v1/webhooks/kuda', $payload, $headers);
        $second = $this->postJson('/api/v1/webhooks/kuda', $payload, $headers);

        $first->assertOk()->assertJsonPath('data.status', 'PROCESSED');
        $second->assertOk()->assertJsonPath('data.duplicate', true);

        $this->assertSame(1, ProviderWebhook::where('provider_event_id', 'kuda|Bill.Transaction|'.$kudaRequestRef.'|mtn77777')->count());
        // Debited exactly once.
        $this->assertSame(500000 - 100500, (int) $user->wallet->fresh()->control_balance);
        $this->assertTrue(app(LedgerService::class)->integrityReport()['balanced']);
    }

    public function test_non_bill_kuda_events_are_ignored(): void
    {
        [$user, $reference] = $this->makeVerifyingKudaBill();

        $payload = [
            'eventType' => 'Transaction.Notification',
            'amount' => 1000,
            'transactionReference' => '999',
            'instrumentNumber' => 'ITR-1',
            'transactionType' => 'Debit',
        ];

        $this->postJson('/api/v1/webhooks/kuda', $payload, [
            'username' => self::WH_USER,
            'password' => base64_encode(self::WH_PASS),
        ])->assertOk()->assertJsonPath('data.status', 'RECEIVED');

        // Bill still verifying (the unrelated event did not touch it).
        $this->assertSame('VERIFYING', Transaction::where('reference', $reference)->fresh()->status);
    }
}
