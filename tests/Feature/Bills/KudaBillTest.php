<?php

namespace Tests\Feature\Bills;

use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Providers\DTOs\BillValidationRequest;
use App\Domain\Transactions\Enums\TransactionType;
use App\Infrastructure\Providers\Kuda\KudaBillProvider;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

/**
 * Bill payments over the Kuda Business API (airtime / data / betting).
 * All Kuda HTTP traffic is faked.
 */
final class KudaBillTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ase.kuda.base_url' => 'https://kuda-openapi-uat.kudabank.com/v2.1',
            'ase.kuda.api_key' => 'kuda-test-key',
            'ase.kuda.email' => 'business@example.com',
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
     * @param  array<string, array>  $byServiceType  serviceType => response body (200)
     */
    private function fakeKuda(array $byServiceType = []): void
    {
        Http::fake(function ($request) use ($byServiceType) {
            if (str_contains($request->url(), 'GetToken')) {
                return Http::response($this->fakeJwt());
            }

            $body = json_decode($request->body(), true);
            $serviceType = (string) ($body['serviceType'] ?? '');

            $default = $byServiceType[$serviceType] ?? ['responseCode' => 'K00'];

            return Http::response($default);
        });
    }

    /**
     * Fund a user's wallet (mock rail) and return auth headers.
     */
    private function fundedUser(int $balanceKobo): array
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'kuda-bill-fund')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', ['amount' => $balanceKobo])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        return [$user, $token];
    }

    public function test_catalog_endpoint_returns_kuda_billers(): void
    {
        $this->fakeKuda([
            'GET_BILLERS_BY_TYPE' => [
                [
                    'Id' => 'a156efb6-af1e-4cca-b8d9-efc8ce7eb77e',
                    'Name' => 'MTN NG VTU',
                    'Description' => 'Airtime',
                    'billItems' => [
                        ['kudaIdentifier' => 'KD-VTU-MTNNG', 'Name' => 'MTN Airtime', 'Description' => 'Airtime'],
                    ],
                ],
            ],
        ]);

        [$user, $token] = $this->fundedUser(100000);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/bills/kuda/catalog?category=airtime')
            ->assertOk()
            ->assertJsonPath('data.category', 'airtime')
            ->assertJsonPath('data.billers.0.Name', 'MTN NG VTU');
    }

    public function test_airtime_purchase_via_kuda_verifies_to_completed(): void
    {
        $this->fakeKuda([
            'ADMIN_PURCHASE_BILL' => [
                'responseCode' => 'K00',
                'BillResponseReference' => 'mtn12345',
                'message' => 'Request received',
            ],
            'BILL_TSQ' => [
                'transactionStatus' => 3,
                'finalStatus' => 'Completed',
                'billResponseReference' => 'mtn12345',
            ],
        ]);

        [$user, $token] = $this->fundedUser(500000); // ₦5,000

        $headers = array_merge($this->authHeaders($token), [
            'Idempotency-Key' => 'kuda-airtime-1',
            'X-Transaction-Pin' => '1234',
        ]);

        $reference = $this->withHeaders($headers)
            ->postJson('/api/v1/bills/pay', [
                'type' => 'AIRTIME',
                'amount' => 100000, // ₦1,000
                'phone' => '08031234567',
                'provider' => 'kuda',
                'biller' => 'KD-VTU-MTNNG',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'VERIFYING') // K00 = received, not final
            ->assertJsonPath('data.provider', 'kuda')
            ->assertJsonPath('data.provider_reference', 'mtn12345')
            ->json('data.reference');

        // The short Kuda requestRef is persisted for webhooks/TSQ.
        $txn = Transaction::where('reference', $reference)->first()->fresh();
        $kudaRequestRef = $txn->metadata['kuda_request_ref'] ?? null;
        $this->assertIsString($kudaRequestRef);
        $this->assertMatchesRegularExpression('/^KB\d{12}[A-Z0-9]{4}$/', $kudaRequestRef);

        // Funds held in reservation.
        $this->assertSame(100500, (int) $user->wallet->fresh()->reserved_balance); // amount + ₦5 flat fee

        // The purchase used a whole-Naira string amount and the explicit biller.
        Http::assertSent(function ($request) {
            if (str_contains($request->url(), 'GetToken')) {
                return false;
            }

            $body = json_decode($request->body(), true);

            return ($body['serviceType'] ?? '') === 'ADMIN_PURCHASE_BILL'
                && ($body['data']['Amount'] ?? null) === '1000'
                && ($body['data']['BillItemIdentifier'] ?? null) === 'KD-VTU-MTNNG'
                && ($body['requestRef'] ?? '') !== '';
        });

        // TSQ confirms completion -> COMPLETED, reservation committed.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/v1/transactions/{$reference}/verify")
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED');

        $wallet = $user->wallet->fresh();
        $this->assertSame(500000 - 100500, (int) $wallet->control_balance);
        $this->assertSame(0, (int) $wallet->reserved_balance);
        $this->assertTrue(app(LedgerService::class)->integrityReport()['balanced']);
    }

    public function test_betting_purchase_without_biller_fails_cleanly(): void
    {
        $this->fakeKuda();

        [$user, $token] = $this->fundedUser(500000);

        $this->withHeaders(array_merge($this->authHeaders($token), [
            'Idempotency-Key' => 'kuda-betting-1',
            'X-Transaction-Pin' => '1234',
        ]))
            ->postJson('/api/v1/bills/pay', [
                'type' => 'BETTING',
                'amount' => 100000,
                'phone' => '08031234567',
                'provider' => 'kuda',
                // no `biller` — betting requires an explicit bookmaker item
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'FAILED');

        // Reservation released, balance untouched.
        $wallet = $user->wallet->fresh();
        $this->assertSame(500000, (int) $wallet->control_balance);
        $this->assertSame(0, (int) $wallet->reserved_balance);
    }

    public function test_kuda_purchase_failure_is_definitive(): void
    {
        $this->fakeKuda([
            'ADMIN_PURCHASE_BILL' => [
                'responseCode' => 'K01',
                'finalStatus' => 'Failed',
                'message' => 'Biller rejected the request',
                'BillResponseReference' => 'mtn99999',
            ],
        ]);

        [$user, $token] = $this->fundedUser(500000);

        $this->withHeaders(array_merge($this->authHeaders($token), [
            'Idempotency-Key' => 'kuda-fail-1',
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
            ->assertJsonPath('data.status', 'FAILED');

        $wallet = $user->wallet->fresh();
        $this->assertSame(500000, (int) $wallet->control_balance);
        $this->assertSame(0, (int) $wallet->reserved_balance);
    }

    public function test_unregistered_bill_provider_is_rejected(): void
    {
        $this->fakeKuda();

        [$user, $token] = $this->fundedUser(500000);

        $this->withHeaders(array_merge($this->authHeaders($token), [
            'Idempotency-Key' => 'kuda-bad-provider-1',
            'X-Transaction-Pin' => '1234',
        ]))
            ->postJson('/api/v1/bills/pay', [
                'type' => 'AIRTIME',
                'amount' => 100000,
                'phone' => '08031234567',
                'provider' => 'does-not-exist',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'PROVIDER_NOT_FOUND');
    }

    /**
     * The GET_BILLERS_BY_TYPE envelope as documented by Kuda:
     * `{ Status, Message, Data: { Billers: [ ... BillItems ... ] } }`.
     *
     * @return array<string, mixed>
     */
    private function documentedCatalogShape(): array
    {
        return [
            'Status' => true,
            'Message' => 'Operation successful!',
            'Data' => [
                'Billers' => [
                    [
                        'Id' => 'a156efb6-af1e-4cca-b8d9-efc8ce7eb77e',
                        'Name' => 'MTN NG VTU',
                        'Description' => 'Airtime',
                        'BillTypeId' => '22393d6f-e830-4a3a-b1c0-85c6dc007b98',
                        'BillItems' => [
                            ['KudaIdentifier' => 'KD-VTU-MTNNG', 'Name' => 'MTN Airtime'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function test_catalog_endpoint_unwraps_documented_billers_envelope(): void
    {
        $this->fakeKuda([
            'GET_BILLERS_BY_TYPE' => $this->documentedCatalogShape(),
        ]);

        [$user, $token] = $this->fundedUser(100000);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/v1/bills/kuda/catalog?category=airtime')
            ->assertOk()
            ->assertJsonPath('data.category', 'airtime')
            ->assertJsonPath('data.billers.0.Name', 'MTN NG VTU')
            ->assertJsonPath('data.billers.0.BillItems.0.KudaIdentifier', 'KD-VTU-MTNNG');
    }

    public function test_airtime_auto_resolution_uses_documented_catalog_shape(): void
    {
        $this->fakeKuda([
            'GET_BILLERS_BY_TYPE' => $this->documentedCatalogShape(),
            'ADMIN_PURCHASE_BILL' => [
                'responseCode' => 'K00',
                'BillResponseReference' => 'mtn-auto-1',
                'message' => 'Request received',
            ],
        ]);

        [$user, $token] = $this->fundedUser(500000);

        // No `biller`: 0803… → MTN → resolved from the catalog. The item
        // identifier lives on the nested BillItems entry in the documented
        // shape — auto-resolution must find it there.
        $this->withHeaders(array_merge($this->authHeaders($token), [
            'Idempotency-Key' => 'kuda-auto-1',
            'X-Transaction-Pin' => '1234',
        ]))
            ->postJson('/api/v1/bills/pay', [
                'type' => 'AIRTIME',
                'amount' => 100000,
                'phone' => '08031234567',
                'provider' => 'kuda',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'VERIFYING');

        Http::assertSent(function ($request) {
            if (str_contains($request->url(), 'GetToken')) {
                return false;
            }

            $body = json_decode($request->body(), true);

            return ($body['serviceType'] ?? '') === 'ADMIN_PURCHASE_BILL'
                && ($body['data']['BillItemIdentifier'] ?? null) === 'KD-VTU-MTNNG'
                && ($body['data']['Amount'] ?? null) === '1000'
                // CustomerFirstName is not part of the documented
                // ADMIN_PURCHASE_BILL sample — omitted when unknown.
                && ! array_key_exists('CustomerFirstName', (array) ($body['data'] ?? []));
        });
    }

    public function test_purchase_stores_kuda_reference_from_documented_envelope(): void
    {
        $this->fakeKuda([
            // The documented ADMIN_PURCHASE_BILL receipt: nested
            // Data.Reference is Kuda's bill response reference.
            'ADMIN_PURCHASE_BILL' => [
                'Status' => true,
                'Message' => 'Your bill purchase request is successful',
                'Data' => ['Reference' => 'BMvgmekaTqPatgIU0', 'Pin' => null],
            ],
        ]);

        [$user, $token] = $this->fundedUser(500000);

        $this->withHeaders(array_merge($this->authHeaders($token), [
            'Idempotency-Key' => 'kuda-ref-1',
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
            ->assertJsonPath('data.provider_reference', 'BMvgmekaTqPatgIU0');
    }

    public function test_validate_customer_reads_documented_envelope(): void
    {
        $provider = app(KudaBillProvider::class);

        Http::fake([
            'kuda-openapi-uat.kudabank.com/v2.1/Account/GetToken' => Http::response($this->fakeJwt()),
            'kuda-openapi-uat.kudabank.com/*' => Http::response([
                'StatusCode' => 'k00',
                'Status' => true,
                'Message' => 'Operation successful!',
                'Data' => ['CustomerName' => 'JOHN DOE'],
            ]),
        ]);

        $result = $provider->validateCustomer(new BillValidationRequest(
            providerName: 'kuda',
            category: TransactionType::Airtime,
            phoneNumber: '08031234567',
            metadata: ['kuda_bill_item' => 'KD-VTU-MTNNG'],
        ));

        $this->assertTrue($result->valid);
        $this->assertSame('JOHN DOE', $result->customerName);

        // The documented rejection envelope carries a BOOLEAN Status=false.
        Http::fake([
            'kuda-openapi-uat.kudabank.com/v2.1/Account/GetToken' => Http::response($this->fakeJwt()),
            'kuda-openapi-uat.kudabank.com/*' => Http::response([
                'StatusCode' => 'k01',
                'Status' => false,
                'Message' => 'Customer not found',
            ]),
        ]);

        $rejected = $provider->validateCustomer(new BillValidationRequest(
            providerName: 'kuda',
            category: TransactionType::Airtime,
            phoneNumber: '08039990002',
            metadata: ['kuda_bill_item' => 'KD-VTU-MTNNG'],
        ));

        $this->assertFalse($rejected->valid);
        $this->assertSame('Customer not found', $rejected->errorMessage);
    }
}
