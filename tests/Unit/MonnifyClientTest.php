<?php

namespace Tests\Unit;

use App\Exceptions\FinancialException;
use App\Exceptions\ProviderDeclinedException;
use App\Infrastructure\Providers\Monnify\MonnifyClient;
use Closure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class MonnifyClientTest extends TestCase
{
    use RefreshDatabase; // AppConfigService consults the app_config table

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'ase.monnify.base_url' => 'https://sandbox.monnify.com',
            'ase.monnify.api_key' => 'MK_TEST_123',
            'ase.monnify.secret_key' => 'secret-123',
            'ase.monnify.contract_code' => '8389328412',
            'ase.monnify.source_account_number' => '9999999999',
        ]);
    }

    /**
     * Fake the login endpoint plus one more pattern in the SAME
     * Http::fake call (a second call would replace the first fake).
     */
    private function fakeTokenAnd(string $pattern, Closure $callback): void
    {
        Http::fake([
            'sandbox.monnify.com/api/v1/auth/login' => Http::response([
                'requestSuccessful' => true,
                'responseCode' => '0',
                'responseBody' => ['accessToken' => 'test-token', 'expiresIn' => 3600],
            ]),
            $pattern => $callback,
        ]);
    }

    public function test_to_naira_string_uses_integer_math(): void
    {
        $this->assertSame('1000.00', MonnifyClient::toNairaString(100000));
        $this->assertSame('2526.00', MonnifyClient::toNairaString(252600));
        $this->assertSame('1.00', MonnifyClient::toNairaString(100));
        $this->assertSame('1.50', MonnifyClient::toNairaString(150));
        $this->assertSame('0.05', MonnifyClient::toNairaString(5));
    }

    public function test_access_token_is_cached(): void
    {
        $this->fakeTokenAnd('sandbox.monnify.com/*', fn () => Http::response(['responseBody' => []]));

        $client = app(MonnifyClient::class);

        $this->assertSame('test-token', $client->accessToken());
        $this->assertSame('test-token', $client->accessToken());

        Http::assertSentCount(1);
    }

    public function test_login_uses_basic_auth_of_api_and_secret_key(): void
    {
        $this->fakeTokenAnd('sandbox.monnify.com/*', fn () => Http::response(['responseBody' => []]));

        app(MonnifyClient::class)->accessToken();

        Http::assertSent(function ($request) {
            return str_contains($request->url(), '/api/v1/auth/login')
                && $request->hasHeader('Authorization', 'Basic '.base64_encode('MK_TEST_123:secret-123'));
        });
    }

    public function test_returns_response_body_on_success(): void
    {
        $this->fakeTokenAnd('sandbox.monnify.com/api/v2/disbursements/single', Http::response([
            'requestSuccessful' => true,
            'responseCode' => '0',
            'responseBody' => ['reference' => 'REF1', 'status' => 'PENDING_AUTHORIZATION'],
        ]));

        $result = app(MonnifyClient::class)->singleTransfer(250000, 'ASE_T_1', '232', '0123456789', 'JOHN DOE');

        $this->assertSame('PENDING_AUTHORIZATION', $result['status']);

        Http::assertSent(function ($request) {
            $body = json_decode($request->body(), true);

            // amount is a numeric value in NGN major units (2500, not
            // "2500.00" and not kobo).
            return $request->hasHeader('Authorization', 'Bearer test-token')
                && $body['amount'] === 2500;
        });
    }

    public function test_error_envelope_4xx_is_a_definitive_decline(): void
    {
        $this->fakeTokenAnd('sandbox.monnify.com/*', Http::response([
            'requestSuccessful' => false,
            'responseCode' => 'D06',
            'responseMessage' => 'IP address not whitelisted',
            'responseBody' => null,
        ], 400));

        try {
            app(MonnifyClient::class)->singleTransfer(250000, 'ASE_T_1', '232', '0123456789', 'JOHN DOE');
            $this->fail('Expected ProviderDeclinedException');
        } catch (ProviderDeclinedException $e) {
            $this->assertStringContainsString('D06', $e->getMessage());
        }
    }

    public function test_error_envelope_5xx_stays_ambiguous(): void
    {
        $this->fakeTokenAnd('sandbox.monnify.com/*', Http::response([
            'requestSuccessful' => false,
            'responseCode' => '999',
            'responseMessage' => 'Internal error',
            'responseBody' => null,
        ], 500));

        $this->expectException(FinancialException::class);

        app(MonnifyClient::class)->singleTransfer(250000, 'ASE_T_1', '232', '0123456789', 'JOHN DOE');
    }

    public function test_name_enquiry_uses_the_validate_bank_account_endpoint(): void
    {
        $this->fakeTokenAnd('sandbox.monnify.com/*', Http::response([
            'requestSuccessful' => true,
            'responseCode' => '0',
            'responseBody' => [
                'accountNumber' => '0123456789',
                'accountName' => 'JOHN DOE',
                'bankCode' => '058',
                'bankName' => 'GTBank',
            ],
        ]));

        $result = app(MonnifyClient::class)->nameEnquiry('058', '0123456789');

        $this->assertSame('JOHN DOE', $result['accountName']);

        // Current docs: GET /api/v2/disbursements/account/validate
        // ?accountNumber=...&bankCode=... (the "Name Enquiry" service).
        Http::assertSent(function ($request) {
            $url = $request->url();

            return str_contains($url, '/api/v2/disbursements/account/validate')
                && str_contains($url, 'accountNumber=0123456789')
                && str_contains($url, 'bankCode=058')
                && $request->hasHeader('Authorization', 'Bearer test-token');
        });
    }

    public function test_missing_credentials_fail_before_any_request(): void
    {
        config(['ase.monnify.api_key' => '']);

        $this->expectException(FinancialException::class);
        $this->expectExceptionMessage('Monnify credentials are not configured');

        app(MonnifyClient::class)->accessToken();
    }

    public function test_missing_source_account_fails_before_any_request(): void
    {
        config(['ase.monnify.source_account_number' => '']);

        $this->expectException(FinancialException::class);
        $this->expectExceptionMessage('MONNIFY_SOURCE_ACCOUNT');

        app(MonnifyClient::class)->singleTransfer(250000, 'ASE_T_1', '232', '0123456789', 'JOHN DOE');
    }
}
