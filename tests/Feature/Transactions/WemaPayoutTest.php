<?php

namespace Tests\Feature\Transactions;

use App\Domain\Ledger\Services\LedgerService;
use App\Models\Transaction;
use App\Models\WalletReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\CreatesVerifiedUser;
use Tests\TestCase;

/**
 * Wallet -> bank payouts over the Wema (ALAT) payout rail. All Wema HTTP
 * traffic is faked.
 */
final class WemaPayoutTest extends TestCase
{
    use CreatesVerifiedUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ase.wema.api_key' => 'wema-test-key',
            'ase.wema.webhook' => 'https://example.test/api/v1/webhooks/wema',
        ]);
    }


    private const WEMA_SECRET = 'wema-test-secret';

    private function fundedUser(int $balanceKobo): array
    {
        [$user, $token] = $this->verifiedUser();
        $user->update(['pin_hash' => Hash::make('1234')]);

        $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'wema-payout-fund')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/fund', ['amount' => $balanceKobo])
            ->assertOk();

        return [$user, $token];
    }

    private function fakeWema(string $nameEnquiryResult = 'JOHN DOE', string $payoutReference = 'WEMA_PAYOUT_REF'): void
    {
        Http::fake(function (HttpRequest $request) {
            $url = $request->url();

            if (str_contains($url, '/name-enquiry/')) {
                return Http::response([
                    'result' => [
                        'bankCode' => '035',
                        'accountNumber' => '0123456789',
                        'accountName' => $nameEnquiryResult,
                    ],
                    'errorMessage' => null,
                    'errorMessages' => [],
                    'hasError' => false,
                    'timeGenerated' => now()->toIso8601String(),
                ]);
            }

            if (str_contains($url, '/payouts/v2/payouts') && $request->method() === 'POST') {
                return Http::response([
                    'result' => [
                        'reference' => $payoutReference,
                        'status' => 'PENDING',
                    ],
                    'errorMessage' => null,
                    'errorMessages' => [],
                    'hasError' => false,
                    'timeGenerated' => now()->toIso8601String(),
                ]);
            }

            if (str_contains($url, '/payouts/v2/payouts')) {
                return Http::response([
                    'result' => ['reference' => $payoutReference, 'status' => 'COMPLETED'],
                    'errorMessage' => null,
                    'errorMessages' => [],
                    'hasError' => false,
                    'timeGenerated' => now()->toIso8601String(),
                ]);
            }

            return Http::response(['result' => [], 'hasError' => false]);
        });
    }

    public function test_wema_payout_is_accepted_and_settles_on_webhook(): void
    {
        $this->fakeWema();
        config(['ase.webhook_secrets.wema' => self::WEMA_SECRET]);

        [$user, $token] = $this->fundedUser(1000000); // ₦10,000

        $reference = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'wema-payout-1')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/payout', [
                'amount' => 250000,
                'bank_code' => '035',
                'account_number' => '0123456789',
                'account_name' => 'JOHN DOE',
                'provider' => 'wema',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'VERIFYING')
            ->assertJsonPath('data.type', 'BANK_TRANSFER')
            ->assertJsonPath('data.provider', 'wema')
            ->assertJsonPath('data.provider_reference', 'WEMA_PAYOUT_REF')
            ->json('data.reference');

        // Funds held in reservation.
        $wallet = $user->wallet->fresh();
        $this->assertSame(252600, (int) $wallet->reserved_balance); // 250000 + 2600 fee

        $payload = [
            'title' => 'Payout Notification',
            'message' => 'Transfer successful',
            'data' => [
                'status' => 'SUCCESSFUL',
                'message' => 'Transfer successful',
                'narration' => 'Transfer',
                'transactionReference' => $reference,
                'platformTransactionReference' => 'WEMA_TRX_P',
            ],
        ];

        $this->postJson('/api/v1/webhooks/wema', $payload, [
            'X-Webhook-Signature' => hash_hmac('sha256', json_encode($payload), self::WEMA_SECRET),
        ])->assertOk()->assertJsonPath('data.status', 'PROCESSED');

        $txn = Transaction::where('reference', $reference)->first()->fresh();
        $this->assertSame('COMPLETED', $txn->status);

        // Wallet debited by amount + fee; reservation committed.
        $this->assertSame(1000000 - 252600, (int) $user->wallet->fresh()->control_balance);
        $this->assertSame(
            \App\Domain\Wallet\Enums\WalletReservationStatus::Committed->value,
            WalletReservation::find($txn->reservation_id)?->status,
        );

        $this->assertTrue(app(LedgerService::class)->integrityReport()['balanced']);
    }

    public function test_wema_payout_fails_cleanly_when_beneficiary_cannot_be_confirmed(): void
    {
        $this->fakeWema(''); // name enquiry finds no account
        config(['ase.webhook_secrets.wema' => self::WEMA_SECRET]);

        [$user, $token] = $this->fundedUser(1000000);

        $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'wema-payout-2')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/payout', [
                'amount' => 250000,
                'bank_code' => '035',
                'account_number' => '0999999999',
                'account_name' => 'NOBODY',
                'provider' => 'wema',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.type', 'BANK_TRANSFER');

        // Nothing left reserved, balance untouched.
        $wallet = $user->wallet->fresh();
        $this->assertSame(1000000, (int) $wallet->control_balance);
        $this->assertSame(0, (int) $wallet->reserved_balance);
    }

    public function test_pending_wema_callback_is_acknowledged_without_state_change(): void
    {
        $this->fakeWema();
        config(['ase.webhook_secrets.wema' => self::WEMA_SECRET]);

        [$user, $token] = $this->fundedUser(1000000);

        $reference = $this->withHeaders($this->authHeaders($token))
            ->withHeader('Idempotency-Key', 'wema-payout-3')
            ->withHeader('X-Transaction-Pin', '1234')
            ->postJson('/api/v1/wallet/payout', [
                'amount' => 250000,
                'bank_code' => '035',
                'account_number' => '0123456789',
                'account_name' => 'JOHN DOE',
                'provider' => 'wema',
            ])
            ->assertOk()
            ->json('data.reference');

        $payload = [
            'title' => 'Payout Notification',
            'message' => 'Processing',
            'data' => [
                'status' => 'PENDING',
                'message' => 'Processing',
                'transactionReference' => $reference,
            ],
        ];

        $this->postJson('/api/v1/webhooks/wema', $payload, [
            'X-Webhook-Signature' => hash_hmac('sha256', json_encode($payload), self::WEMA_SECRET),
        ])->assertOk()->assertJsonPath('data.status', 'RECEIVED');

        // Still verifying; funds still reserved.
        $this->assertSame('VERIFYING', Transaction::where('reference', $reference)->first()->fresh()->status);
        $this->assertSame(252600, (int) $user->wallet->fresh()->reserved_balance);
    }
}
