<?php

namespace Tests\Unit;

use App\Exceptions\FinancialException;
use App\Exceptions\ProviderDeclinedException;
use App\Infrastructure\Providers\Wema\WemaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WemaClientTest extends TestCase
{
    use RefreshDatabase; // AppConfigService consults the app_config table

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ase.wema.api_key' => 'test-subscription-key',
            'ase.wema.base_url' => 'https://wema-alatdev-apimgt.developer.azure-api.net',
            'ase.wema.webhook' => 'https://example.com/api/v1/webhooks/wema',
        ]);
    }

    private function envelope(array $result, bool $hasError = false, ?string $errorMessage = null): array
    {
        return [
            'result' => $result,
            'errorMessage' => $errorMessage,
            'errorMessages' => $errorMessage === null ? [] : [$errorMessage],
            'hasError' => $hasError,
            'timeGenerated' => now()->toIso8601String(),
        ];
    }

    public function test_returns_result_on_success(): void
    {
        Http::fake([
            'wema-alatdev-apimgt.developer.azure-api.net/*' => Http::response($this->envelope([
                'reference' => 'REF1',
                'status' => 'PENDING',
                'virtualAccount' => '0310000123',
                'virtualBank' => 'Wema Bank',
            ])),
        ]);

        $result = app(WemaClient::class)->createPaymentRequest(100000, 'ASE_T_1', 'Wallet funding');

        $this->assertSame('REF1', $result['reference']);
        $this->assertSame('0310000123', $result['virtualAccount']);

        Http::assertSent(function ($request) {
            return $request->hasHeader('Api-Key', 'test-subscription-key')
                && str_contains($request->url(), '/payments/v1/paymentrequests');
        });
    }

    public function test_has_error_envelope_throws(): void
    {
        Http::fake([
            'wema-alatdev-apimgt.developer.azure-api.net/*' => Http::response(
                $this->envelope(null, true, 'Insufficient balance on source account'),
                200,
            ),
        ]);

        $this->expectException(FinancialException::class);

        app(WemaClient::class)->getPayout('REF1');
    }

    public function test_http_4xx_is_a_definitive_decline(): void
    {
        Http::fake([
            'wema-alatdev-apimgt.developer.azure-api.net/*' => Http::response(
                $this->envelope(null, true, 'Invalid Channel ID'),
                401,
            ),
        ]);

        try {
            app(WemaClient::class)->getPaymentRequest('REF1');
            $this->fail('Expected ProviderDeclinedException');
        } catch (ProviderDeclinedException $e) {
            $this->assertSame('WEMA_API_ERROR', $e->errorCode());
        }
    }

    public function test_http_5xx_stays_ambiguous(): void
    {
        Http::fake([
            'wema-alatdev-apimgt.developer.azure-api.net/*' => Http::response(
                $this->envelope(null, true, 'Internal error'),
                500,
            ),
        ]);

        $this->expectException(FinancialException::class);

        app(WemaClient::class)->getPaymentRequest('REF1');
    }

    public function test_timeout_is_rethrown_for_the_classifier(): void
    {
        Http::fake([
            'wema-alatdev-apimgt.developer.azure-api.net/*' => fn () => throw new ConnectionException('timed out'),
        ]);

        $this->expectException(ConnectionException::class);

        app(WemaClient::class)->getPayout('REF1');
    }

    public function test_missing_api_key_fails_configuration_not_request(): void
    {
        config(['ase.wema.api_key' => '']);

        $this->expectException(FinancialException::class);
        $this->expectExceptionMessage('Wema API key is not configured');

        app(WemaClient::class)->getPayout('REF1');
    }
}
