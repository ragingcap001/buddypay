<?php

namespace App\Infrastructure\Providers\Wema;

use App\Domain\Config\Services\AppConfigService;
use App\Exceptions\FinancialException;
use App\Exceptions\ProviderDeclinedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the Wema ALAT Developer API (API Management portal).
 *
 * Docs: https://wema-alatdev-apimgt.developer.azure-api.net/
 *
 * - Authentication: every request carries the subscription key in the
 *   `Api-Key` header (the "channel id" issued with the test/production
 *   credentials).
 * - Envelope: every response is shaped
 *   `{ result, errorMessage, errorMessages[], hasError, timeGenerated }`.
 *
 * This class only speaks HTTP — the provider classes above it translate
 * results into the platform's `ProviderOutcome` vocabulary. A request that
 * cannot be delivered (timeout / connection reset) re-throws
 * `ConnectionException`; the gateway's outcome classifier treats that as
 * AMBIGUOUS (never blindly failed over).
 */
final class WemaClient
{
    /** Wema Bank NIP code. */
    public const BANK_CODE = '035';

    /** Default test-portal base URL (production is issued on onboarding). */
    public const DEFAULT_BASE_URL = 'https://wema-alatdev-apimgt.developer.azure-api.net';

    public function __construct(private readonly AppConfigService $config)
    {
    }

    /**
     * The ALAT API prices in whole Naira (major units); the platform prices
     * in integer kobo. Pure integer math, no floats.
     */
    public static function toNaira(int $kobo): int
    {
        if ($kobo % 100 !== 0) {
            throw new ProviderDeclinedException(
                'AMOUNT_NOT_SUPPORTED',
                'Wema (ALAT) amounts must be whole Naira (a multiple of 100 kobo).',
                422,
            );
        }

        return intdiv($kobo, 100);
    }

    private function apiKey(): string
    {
        // Admin-dashboard override first, WEMA_API_KEY as fallback.
        $key = (string) ($this->config->get('wema', 'api_key') ?? '');

        if ($key === '') {
            throw new FinancialException('WEMA_NOT_CONFIGURED', 'Wema API key is not configured (admin dashboard or WEMA_API_KEY).', 503);
        }

        return $key;
    }

    private function baseUrl(): string
    {
        return (string) ($this->config->get('wema', 'base_url') ?? self::DEFAULT_BASE_URL);
    }

    private function webhookUrl(): string
    {
        return (string) ($this->config->get('wema', 'webhook') ?? '');
    }

    /**
     * Perform a request against the ALAT API and return the `result` object.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws ConnectionException when the request could not be delivered or timed out
     * @throws FinancialException  when Wema reports an error (hasError / non-2xx)
     */
    public function request(string $method, string $path, array $body = [], array $query = []): array
    {
        $pending = Http::baseUrl($this->baseUrl())
            ->withHeaders([
                'Api-Key' => $this->apiKey(),
                'Accept' => 'application/json',
            ])
            ->timeout((int) config('ase.wema.timeout_seconds', 10))
            ->connectTimeout((int) config('ase.wema.connect_timeout_seconds', 5));

        try {
            $response = $method === 'GET'
                ? $pending->withQueryParameters($query)->get($path)
                : $pending->post($path, $body);
        } catch (ConnectionException $e) {
            // Once the request may have been transmitted, the outcome is
            // unknown — let the classifier mark it AMBIGUOUS.
            throw $e;
        }

        $decoded = $response->json();
        $envelope = is_array($decoded) ? $decoded : [];

        $hasError = (bool) ($envelope['hasError'] ?? ($decoded === null));

        if ($response->failed() || $hasError) {
            $message = (string) ($envelope['errorMessage'] ?? '');
            $messages = $envelope['errorMessages'] ?? [];

            if (is_array($messages) && $messages !== []) {
                $message .= ' ('.implode('; ', array_map('strval', $messages)).')';
            }

            // 4xx client errors (unauthorized, bad request, not found, ...)
            // are definitive rejections — no transaction was initiated.
            // 5xx / 2xx-with-error may have been partially processed, so
            // they stay ambiguous (verified, never blindly failed over).
            if ($response->status() >= 400 && $response->status() < 500) {
                throw new ProviderDeclinedException(
                    'WEMA_API_ERROR',
                    'Wema rejected the request'.($message !== '' ? ": {$message}" : ' (HTTP '.$response->status().')'),
                    422,
                );
            }

            throw new FinancialException(
                'WEMA_API_ERROR',
                'Wema API error'.($message !== '' ? ": {$message}" : ' (HTTP '.$response->status().')'),
                502,
            );
        }

        return (array) ($envelope['result'] ?? []);
    }

    /* --------------------------------------------------------------------
     | Payments
     * ------------------------------------------------------------------ */

    /**
     * Create a payment request: Wema issues a virtual account for an exact
     * amount; the customer's transfer to it settles the request.
     *
     * POST /payments/v1/paymentrequests
     *
     * @param  array<string, string>  $customer  optional: firstName, lastName, email, mobileNumber
     * @return array<string, mixed>  result: reference, status, virtualAccount, virtualBank
     */
    public function createPaymentRequest(int $amountKobo, string $reference, string $narration, array $customer = [], int $ttl = 3600): array
    {
        $body = [
            'amount' => self::toNaira($amountKobo),
            'narration' => $narration,
            'reference' => $reference,
            'ttl' => $ttl,
        ];

        $webhook = $this->webhookUrl();

        if ($webhook !== '') {
            $body['webhook'] = $webhook;
        }

        if ($customer !== []) {
            $body['customer'] = $customer;
        }

        return $this->request('POST', '/payments/v1/paymentrequests', $body);
    }

    /**
     * Query a payment request's status.
     *
     * GET /payments/v1/paymentrequests/{reference}
     *
     * @return array<string, mixed>  result: reference, status (PENDING | COMPLETED | CANCELLED | EXPIRED), amount
     */
    public function getPaymentRequest(string $reference): array
    {
        return $this->request('GET', '/payments/v1/paymentrequests/'.rawurlencode($reference));
    }

    /* --------------------------------------------------------------------
     | Payouts (bank transfers out)
     * ------------------------------------------------------------------ */

    /**
     * NIP name enquiry — confirm a destination account belongs to the given
     * name before paying it.
     *
     * GET /name-enquiry/v1/name-enquiry/{bankCode}/{accountNumber}
     *
     * @return array<string, mixed>  result: bankCode, accountNumber, accountName
     */
    public function nameEnquiry(string $bankCode, string $accountNumber): array
    {
        return $this->request('GET', '/name-enquiry/v1/name-enquiry/'.rawurlencode($bankCode).'/'.rawurlencode($accountNumber));
    }

    /**
     * Initiate a payout from the merchant's profiled source account to any
     * Nigerian bank account.
     *
     * POST /payouts/v2/payouts
     *
     * @return array<string, mixed>  result: reference (Wema ref), status (usually PENDING)
     */
    public function payout(int $amountKobo, string $reference, string $bankCode, string $accountNumber, string $accountName, string $narration): array
    {
        $body = [
            'amount' => self::toNaira($amountKobo),
            'narration' => $narration,
            'reference' => $reference,
            'beneficiary' => [
                'accountName' => $accountName,
                'accountNumber' => $accountNumber,
                'bankCode' => $bankCode,
                'reference' => $reference,
            ],
        ];

        $webhook = $this->webhookUrl();

        if ($webhook !== '') {
            $body['webhook'] = $webhook;
        }

        return $this->request('POST', '/payouts/v2/payouts', $body);
    }

    /**
     * Query a payout's status.
     *
     * GET /payouts/v2/payouts/{reference}
     *
     * @return array<string, mixed>  result: reference, status (PENDING | COMPLETED | CANCELLED | REVERSED), amount
     */
    public function getPayout(string $reference): array
    {
        return $this->request('GET', '/payouts/v2/payouts/'.rawurlencode($reference));
    }
}
