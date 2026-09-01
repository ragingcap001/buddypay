<?php

namespace App\Infrastructure\Providers\Reloadly;

use App\Domain\Config\Services\AppConfigService;
use App\Exceptions\FinancialException;
use App\Exceptions\ProviderDeclinedException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the Reloadly Gift Cards API.
 *
 * Docs: https://docs.reloadly.com/gift-cards
 *
 * - Auth is a separate OAuth2 client-credentials exchange against
 *   https://auth.reloadly.com/oauth/token — NOT the gift-cards base URL
 *   itself. `audience` on that request must equal whichever gift-cards
 *   base URL (sandbox/production) is in effect; a token issued for one
 *   audience is rejected by the other.
 * - Every request needs `Accept: application/com.reloadly.giftcards-v1+json`
 *   (v2 for the redeem-code endpoint specifically) in addition to the
 *   bearer token — omitting it is a documented source of 406/415 errors.
 */
final class ReloadlyClient
{
    private const AUTH_URL = 'https://auth.reloadly.com/oauth/token';

    public function __construct(private readonly AppConfigService $config)
    {
    }

    private function baseUrl(): string
    {
        return (string) ($this->config->get('reloadly', 'base_url') ?? 'https://giftcards-sandbox.reloadly.com');
    }

    private function requireCredentials(): void
    {
        if ((string) ($this->config->get('reloadly', 'client_id') ?? '') === ''
            || (string) ($this->config->get('reloadly', 'client_secret') ?? '') === '') {
            throw new FinancialException(
                'RELOADLY_NOT_CONFIGURED',
                'Reloadly credentials are not configured (admin dashboard or RELOADLY_CLIENT_ID / RELOADLY_CLIENT_SECRET).',
                503,
            );
        }
    }

    /**
     * Bearer token, cached until shortly before expiry. Cache key is
     * scoped to the base URL too — sandbox and production tokens are not
     * interchangeable, and switching environments in the admin dashboard
     * must not serve a stale token issued for the other one.
     */
    public function accessToken(): string
    {
        $this->requireCredentials();

        $baseUrl = $this->baseUrl();
        $clientId = (string) $this->config->get('reloadly', 'client_id');
        $cacheKey = 'reloadly:token:'.substr(hash('sha256', $clientId.'|'.$baseUrl), 0, 16);

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asJson()
            ->timeout(15)
            ->post(self::AUTH_URL, [
                'client_id' => $clientId,
                'client_secret' => (string) $this->config->get('reloadly', 'client_secret'),
                'grant_type' => 'client_credentials',
                'audience' => $baseUrl,
            ]);

        $body = (array) $response->json();

        if ($response->failed() || ! isset($body['access_token'])) {
            throw new FinancialException(
                'RELOADLY_TOKEN_ERROR',
                'Unable to obtain a Reloadly access token: '.((string) ($body['message'] ?? 'HTTP '.$response->status())),
                502,
            );
        }

        $token = (string) $body['access_token'];
        $ttl = max(30, (int) ($body['expires_in'] ?? 3600) - 30);

        Cache::put($cacheKey, $token, now()->addSeconds($ttl));

        return $token;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<int|string, mixed>
     */
    public function get(string $path, array $query = [], string $acceptVersion = 'v1'): array
    {
        $response = Http::baseUrl($this->baseUrl())
            ->withToken($this->accessToken())
            ->withHeaders(['Accept' => "application/com.reloadly.giftcards-{$acceptVersion}+json"])
            ->timeout(20)
            ->get($path, $query);

        return $this->decode($response, 'GET', $path);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        $response = Http::baseUrl($this->baseUrl())
            ->withToken($this->accessToken())
            ->withHeaders(['Accept' => 'application/com.reloadly.giftcards-v1+json'])
            ->timeout(30)
            ->post($path, $payload);

        return $this->decode($response, 'POST', $path);
    }

    /**
     * @return array<int|string, mixed>
     */
    private function decode(\Illuminate\Http\Client\Response $response, string $method, string $path): array
    {
        $body = $response->json();
        $message = is_array($body) ? (string) ($body['message'] ?? $body['error'] ?? 'unknown error') : 'unknown error';

        if ($response->clientError()) {
            // 400/401/404: Reloadly rejected the request before charging
            // anything — no order was created, so failing definitively is
            // safe (never ambiguous, unlike a 5xx/timeout).
            throw new ProviderDeclinedException(
                'RELOADLY_DECLINED',
                "Reloadly {$method} {$path} rejected the request ({$response->status()}): {$message}",
                $response->status(),
            );
        }

        if ($response->serverError()) {
            throw new FinancialException(
                'RELOADLY_API_ERROR',
                "Reloadly {$method} {$path} failed ({$response->status()}): {$message}",
                502,
            );
        }

        return is_array($body) ? $body : [];
    }
}
