<?php

namespace Tests\Unit;

use App\Exceptions\FinancialException;
use App\Exceptions\ProviderDeclinedException;
use App\Infrastructure\Providers\Kuda\KudaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class KudaClientTest extends TestCase
{
    use RefreshDatabase; // AppConfigService consults the app_config table

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

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

    private function fakeTokenEndpoint(): void
    {
        Http::fake([
            'kuda-openapi-uat.kudabank.com/v2.1/Account/GetToken' => Http::response($this->fakeJwt()),
            'kuda-openapi-uat.kudabank.com/*' => Http::response(['ok' => true]),
        ]);
    }

    public function test_access_token_is_cached_until_expiry(): void
    {
        $this->fakeTokenEndpoint();

        $client = app(KudaClient::class);

        $first = $client->accessToken();
        $second = $client->accessToken();

        $this->assertSame($first, $second);
        $this->assertStringContainsString('.', $first);

        Http::assertSentCount(1);
    }

    public function test_request_sends_envelope_with_bearer_token(): void
    {
        $this->fakeTokenEndpoint();

        app(KudaClient::class)->request('GET_BILLERS_BY_TYPE', ['BillTypeName' => 'airtime'], 'CAT1');

        Http::assertSent(function ($request) {
            $url = $request->url();

            if (str_contains($url, 'GetToken')) {
                return false;
            }

            $body = json_decode($request->body(), true);
            $authorization = (string) $request->header('Authorization')[0];

            return str_contains($url, '/v2.1')
                && str_starts_with($authorization, 'Bearer eyJ')
                && ($body['serviceType'] ?? '') === 'GET_BILLERS_BY_TYPE'
                && ($body['requestRef'] ?? '') === 'CAT1'
                && ($body['data']['BillTypeName'] ?? '') === 'airtime';
        });
    }

    public function test_request_ref_is_short_alphanumeric(): void
    {
        $ref = app(KudaClient::class)->makeRequestRef('KB');

        $this->assertMatchesRegularExpression('/^KB\d{12}[A-Z0-9]{4}$/', $ref);
    }

    public function test_to_naira_string_rejects_sub_naira_amounts(): void
    {
        $this->assertSame('1000', KudaClient::toNairaString(100000));
        $this->assertSame('50000', KudaClient::toNairaString(5000000));

        $this->expectException(ProviderDeclinedException::class);

        KudaClient::toNairaString(10050);
    }

    public function test_http_4xx_is_a_definitive_decline(): void
    {
        $this->fakeTokenEndpoint();

        Http::fake([
            'kuda-openapi-uat.kudabank.com/v2.1/Account/GetToken' => Http::response($this->fakeJwt()),
            'kuda-openapi-uat.kudabank.com/*' => Http::response(['message' => 'Invalid token'], 401),
        ]);

        $this->expectException(ProviderDeclinedException::class);

        app(KudaClient::class)->request('GET_BILLERS_BY_TYPE', []);
    }

    public function test_http_5xx_stays_ambiguous(): void
    {
        $this->fakeTokenEndpoint();

        Http::fake([
            'kuda-openapi-uat.kudabank.com/v2.1/Account/GetToken' => Http::response($this->fakeJwt()),
            'kuda-openapi-uat.kudabank.com/*' => Http::response(['message' => 'Internal error'], 500),
        ]);

        $this->expectException(FinancialException::class);

        app(KudaClient::class)->request('GET_BILLERS_BY_TYPE', []);
    }

    public function test_missing_credentials_fail_before_any_request(): void
    {
        config(['ase.kuda.api_key' => '']);

        $this->expectException(FinancialException::class);
        $this->expectExceptionMessage('Kuda credentials are not configured');

        app(KudaClient::class)->accessToken();
    }
}
