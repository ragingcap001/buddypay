<?php

namespace App\Domain\Notifications\Services;

use App\Domain\Config\Services\AppConfigService;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Firebase Cloud Messaging (HTTP v1) delivery.
 *
 * FCM is the single push transport for both platforms: Android (Google)
 * and iOS (Apple) devices register the same kind of FCM token, and FCM
 * relays iOS deliveries to APNs. The Apple/Google config groups in the
 * admin dashboard hold the client-side identifiers (APNs keys, sender id)
 * needed by the mobile apps; delivery itself only needs the Firebase
 * service account.
 *
 * Auth: a short-lived OAuth2 access token obtained with a self-signed
 * RS256 JWT (openssl — no third-party JWT dependency).
 */
final class FcmService
{
    private const TOKEN_URL = 'https://oauth2.googleapis.com/token';
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function __construct(private readonly AppConfigService $config)
    {
    }

    public function isConfigured(): bool
    {
        return $this->serviceAccount() !== null
            && ($this->config->get('firebase', 'project_id') ?? '') !== '';
    }

    /**
     * @return array{iss: string, private_key: string}|null
     */
    private function serviceAccount(): ?array
    {
        $raw = $this->config->get('firebase', 'service_account');

        if ($raw === null || trim($raw) === '') {
            return null;
        }

        $decoded = json_decode(trim($raw), true);

        if (! is_array($decoded) || ! isset($decoded['client_email'], $decoded['private_key'])) {
            Log::warning('FCM service account JSON is malformed (client_email/private_key missing).');

            return null;
        }

        return $decoded;
    }

    /**
     * FCM access token, cached until shortly before expiry.
     */
    public function accessToken(): string
    {
        $account = $this->serviceAccount();

        if ($account === null) {
            throw new \App\Exceptions\FinancialException('FCM_NOT_CONFIGURED', 'Firebase service account is not configured.', 503);
        }

        $cacheKey = 'fcm:token:'.substr(hash('sha256', (string) $account['client_email']), 0, 16);

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $now = time();
        $header = $this->b64(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $payload = $this->b64(json_encode([
            'iss' => $account['client_email'],
            'scope' => self::SCOPE,
            'aud' => self::TOKEN_URL,
            'iat' => $now,
            'exp' => $now + 3600,
        ]));

        $signature = '';
        $ok = openssl_sign("{$header}.{$payload}", $signature, (string) $account['private_key'], 'sha256');

        if (! $ok) {
            throw new \App\Exceptions\FinancialException('FCM_TOKEN_ERROR', 'Unable to sign the FCM assertion (openssl).', 500);
        }

        $jwt = "{$header}.{$payload}.".($this->b64($signature));

        $response = Http::asForm()->timeout(15)->post(self::TOKEN_URL, [
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt,
        ]);

        $body = (array) $response->json();

        if ($response->failed() || ! isset($body['access_token'])) {
            Log::warning('FCM token exchange failed', ['status' => $response->status(), 'error' => $body['error_description'] ?? $body['error'] ?? null]);

            throw new \App\Exceptions\FinancialException('FCM_TOKEN_ERROR', 'Unable to obtain an FCM access token.', 502);
        }

        $ttl = max(30, (int) ($body['expires_in'] ?? 3600) - 60);

        Cache::put($cacheKey, (string) $body['access_token'], now()->addSeconds($ttl));

        return (string) $body['access_token'];
    }

    /**
     * Send one message to a device token.
     *
     * @param  array<string, string>  $data  optional FCM data payload
     * @return array{ok: bool, message_id: ?string, error: ?string}
     */
    public function send(string $token, string $title, string $body, array $data = []): array
    {
        $project = (string) $this->config->get('firebase', 'project_id');
        $endpoint = 'https://fcm.googleapis.com/v1/projects/'.$project.'/messages:send';

        $message = [
            'message' => [
                'token' => $token,
                'notification' => ['title' => $title, 'body' => $body],
            ],
        ];

        if ($data !== []) {
            $message['message']['data'] = $data;
        }

        try {
            $response = Http::withToken($this->accessToken())
                ->timeout(15)
                ->post($endpoint, $message);
        } catch (\Illuminate\Http\Client\ConnectionException $e) {
            return ['ok' => false, 'message_id' => null, 'error' => 'connection: '.$e->getMessage()];
        }

        $decoded = (array) $response->json();

        if ($response->successful() && isset($decoded['name'])) {
            return ['ok' => true, 'message_id' => (string) $decoded['name'], 'error' => null];
        }

        $error = (string) ($decoded['error']['message'] ?? ('HTTP '.$response->status()));

        // An unregistered token is a normal condition (device reinstalled)
        // — callers should treat it as a soft failure.
        return ['ok' => false, 'message_id' => null, 'error' => $error];
    }

    /**
     * Fan out to all active devices of a user.
     *
     * @param  array<string, string>  $data
     * @return array{sent: int, failed: int}
     */
    public function sendToUser(User $user, string $title, string $body, array $data = []): array
    {
        $devices = $user->pushDevices()->where('active', true)->get();

        $sent = 0;
        $failed = 0;

        foreach ($devices as $device) {
            $result = $this->send((string) $device->token, $title, $body, $data);

            if ($result['ok']) {
                $sent++;
                $device->update(['last_used_at' => now()]);
            } else {
                $failed++;
                Log::info('FCM delivery failed', [
                    'user_id' => $user->id,
                    'device' => $device->id,
                    'error' => $result['error'],
                ]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }

    private function b64(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
