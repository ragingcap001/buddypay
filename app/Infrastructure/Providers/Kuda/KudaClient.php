<?php

namespace App\Infrastructure\Providers\Kuda;

use App\Domain\Config\Services\AppConfigService;
use App\Exceptions\FinancialException;
use App\Exceptions\ProviderDeclinedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Thin HTTP client for the Kuda Business API (Open API v2.1).
 *
 * Docs: https://docs.kuda.com/ + business-support.kuda.com (Business API)
 *
 * - One endpoint for all operations; the operation is chosen via
 *   `serviceType`. Envelope: { serviceType, requestRef, data }.
 * - Auth: POST {base}/Account/GetToken with { email, apiKey } returns a raw
 *   JWT string, sent as `Authorization: Bearer <jwt>`. The JWT expiry is
 *   decoded and the token cached until shortly before it lapses.
 * - `requestRef` rules (per Kuda): short, unique, alphanumeric. Our platform
 *   references are longer/underscored, so bill purchases use a generated
 *   short ref (`KB{ymdHis}{4alnum}`) which is persisted on the transaction
 *   metadata (`kuda_request_ref`) for webhooks and TSQ lookups.
 *
 * Response envelopes vary by operation and are parsed leniently (unknown
 * fields are ignored).
 */
final class KudaClient
{
    public const UAT_BASE_URL = 'https://kuda-openapi-uat.kudabank.com/v2.1';
    public const PRODUCTION_BASE_URL = 'https://kuda-openapi.kuda.com/v2.1';

    public function __construct(private readonly AppConfigService $config)
    {
    }

    private function baseUrl(): string
    {
        return (string) ($this->config->get('kuda', 'base_url') ?? self::UAT_BASE_URL);
    }

    private function requireCredentials(): void
    {
        if ((string) ($this->config->get('kuda', 'api_key') ?? '') === ''
            || (string) ($this->config->get('kuda', 'email') ?? '') === '') {
            throw new FinancialException(
                'KUDA_NOT_CONFIGURED',
                'Kuda credentials are not configured (admin dashboard or KUDA_API_KEY / KUDA_BUSINESS_EMAIL).',
                503,
            );
        }
    }

    /**
     * Kuda access token (raw JWT), cached until shortly before expiry.
     */
    public function accessToken(): string
    {
        $this->requireCredentials();

        $apiKey = (string) $this->config->get('kuda', 'api_key');
        $cacheKey = 'kuda:token:'.substr(hash('sha256', $apiKey), 0, 16);

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->timeout(15)
            ->post('/Account/GetToken', [
                'email' => (string) $this->config->get('kuda', 'email'),
                'apiKey' => $apiKey,
            ]);

        // The token endpoint returns a RAW JWT string, not JSON.
        $token = trim((string) $response->body());

        if ($response->failed() || ! str_contains($token, '.')) {
            throw new FinancialException(
                'KUDA_TOKEN_ERROR',
                'Unable to obtain a Kuda access token (HTTP '.$response->status().').',
                502,
            );
        }

        $ttl = max(60, $this->jwtExpiresIn($token) - 60);

        Cache::put($cacheKey, $token, now()->addSeconds($ttl));

        return $token;
    }

    /** Decode the `exp` claim from a JWT (pure string ops). Seconds left, or a default 30 min. */
    private function jwtExpiresIn(string $jwt): int
    {
        $parts = explode('.', $jwt);

        if (count($parts) < 2) {
            return 1800;
        }

        $payload = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
        $exp = (int) ($payload['exp'] ?? 0);

        if ($exp <= time()) {
            return 1800;
        }

        return $exp - time();
    }

    /**
     * Short alphanumeric request reference (Kuda guidance: short, unique,
     * no special characters for money movement).
     */
    public function makeRequestRef(string $prefix = 'KB'): string
    {
        return $prefix.date('ymdHis').Str::upper(Str::random(4));
    }

    /**
     * Execute a Kuda operation.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>  the decoded response payload (lenient shape)
     *
     * @throws ConnectionException when the request could not be delivered/timed out
     */
    public function request(string $serviceType, array $data = [], ?string $requestRef = null): array
    {
        $pending = Http::baseUrl($this->baseUrl())
            ->withToken($this->accessToken())
            ->acceptJson()
            ->timeout(20)
            ->connectTimeout(5);

        try {
            $response = $pending->post('/', [
                'serviceType' => strtoupper($serviceType),
                'requestRef' => $requestRef ?? $this->makeRequestRef(substr(strtoupper($serviceType), 0, 2)),
                'data' => $data,
            ]);
        } catch (ConnectionException $e) {
            throw $e;
        }

        $decoded = $response->json();
        $payload = is_array($decoded) ? $decoded : (is_string($decoded) && $decoded !== '' ? ['raw' => $decoded] : []);

        if ($response->failed()) {
            $message = (string) ($payload['message'] ?? $payload['Message'] ?? $payload['error'] ?? '');

            // 4xx = definitive rejection (nothing was submitted).
            if ($response->status() >= 400 && $response->status() < 500) {
                throw new ProviderDeclinedException(
                    'KUDA_API_ERROR',
                    'Kuda rejected the request'.($message !== '' ? ": {$message}" : ' (HTTP '.$response->status().')'),
                    422,
                );
            }

            throw new FinancialException(
                'KUDA_API_ERROR',
                'Kuda API error'.($message !== '' ? ": {$message}" : ' (HTTP '.$response->status().')'),
                502,
            );
        }

        return $payload;
    }

    /* --------------------------------------------------------------------
     | Bill operations
     * ------------------------------------------------------------------ */

    /**
     * GET_BILLERS_BY_TYPE — billers + purchasable billItems (each with a
     * `kudaIdentifier`) for one category: airtime, "internet data",
     * betting, electricity, cabletv, ...
     *
     * @return array<int|string, mixed>
     */
    public function getBillersByType(string $billTypeName): array
    {
        $payload = $this->request('GET_BILLERS_BY_TYPE', ['BillTypeName' => $billTypeName]);

        return $this->findValue($payload, ['billers', 'Billers', 'data', 'Data', 'result', 'Result']) ?? $payload;
    }

    /**
     * VERIFY_BILL_CUSTOMER — validate a customer reference (phone, meter
     * number, ...) against a specific bill item.
     */
    public function verifyBillCustomer(string $billItemIdentifier, string $customerIdentifier): array
    {
        return $this->request('VERIFY_BILL_CUSTOMER', [
            'KudaBillItemIdentifier' => $billItemIdentifier,
            'CustomerIdentification' => $customerIdentifier,
        ]);
    }

    /**
     * ADMIN_PURCHASE_BILL — execute a bill payment from the business
     * account. `Amount` is a string in NAIRA (major units).
     *
     * @param  array<string, mixed>  $data  CustomerFirstName, CustomerIdentifier, PhoneNumber, BillItemIdentifier, Amount
     */
    public function purchaseBill(array $data, string $requestRef): array
    {
        return $this->request('ADMIN_PURCHASE_BILL', $data, $requestRef);
    }

    /**
     * BILL_TSQ — bill transaction status query. transactionStatus:
     * 3 = completed, 1 = pending. May carry the final PIN/token.
     */
    public function billTsq(?string $billResponseReference, ?string $billRequestRef): array
    {
        $data = [];

        if ($billResponseReference !== null && $billResponseReference !== '') {
            $data['billResponseReference'] = $billResponseReference;
        }

        if ($billRequestRef !== null && $billRequestRef !== '') {
            $data['BillRequestRef'] = $billRequestRef;
        }

        return $this->request('BILL_TSQ', $data);
    }

    /* --------------------------------------------------------------------
     | Helpers
     * ------------------------------------------------------------------ */

    /**
     * Kobo -> whole-Naira string for Kuda's string amounts.
     */
    public static function toNairaString(int $kobo): string
    {
        if ($kobo % 100 !== 0) {
            throw new ProviderDeclinedException(
                'AMOUNT_NOT_SUPPORTED',
                'Kuda bill amounts must be whole Naira (a multiple of 100 kobo).',
                422,
            );
        }

        return (string) intdiv($kobo, 100);
    }

    /**
     * Case-insensitive first-match lookup across a set of candidate keys.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return mixed
     */
    public static function findValue(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            foreach ($data as $actualKey => $value) {
                if (strcasecmp((string) $actualKey, $key) === 0) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * First non-empty value among candidate keys (case-insensitive).
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     */
    public static function firstString(array $data, array $keys): ?string
    {
        $value = self::findValue($data, $keys);

        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }
}
