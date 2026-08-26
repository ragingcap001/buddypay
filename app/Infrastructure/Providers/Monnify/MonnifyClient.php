<?php

namespace App\Infrastructure\Providers\Monnify;

use App\Domain\Config\Services\AppConfigService;
use App\Exceptions\FinancialException;
use App\Exceptions\ProviderDeclinedException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Thin HTTP client for the Monnify API.
 *
 * Docs: https://developers.monnify.com/
 *
 * - Authentication: OAuth 2.0 client-credentials Bearer token
 *   (`POST /api/v2/oauth/token` with api_key + contract_code + secret_key).
 *   Tokens are short-lived and cached in the cache store until shortly
 *   before expiry.
 * - Envelope: every response is shaped
 *   `{ requestSuccessful, responseMessage, responseCode, responseBody }`.
 * - Amounts: the Monnify API prices in NGN major units with two decimals
 *   (e.g. 1000.00); the platform prices in integer kobo. Conversion is
 *   pure integer math (`toNairaString`).
 *
 * This class only speaks HTTP — the provider classes above it translate
 * results into the platform's `ProviderOutcome` vocabulary.
 */
final class MonnifyClient
{
    /** Sandbox base URL (live: https://api.monnify.com). */
    public const DEFAULT_BASE_URL = 'https://sandbox.monnify.com';

    public function __construct(private readonly AppConfigService $config)
    {
    }

    /**
     * Effective Monnify config value (admin-dashboard override first,
     * MONNIFY_* env as fallback).
     */
    private function val(string $key): string
    {
        return (string) ($this->config->get('monnify', $key) ?? '');
    }

    /**
     * Convert integer kobo to the Monnify "major units, two decimals"
     * representation using pure integer math (no floats).
     */
    public static function toNairaString(int $kobo): string
    {
        $sign = $kobo < 0 ? '-' : '';
        $abs = abs($kobo);

        return $sign
            .number_format(intdiv($abs, 100), 0, '.', '')
            .'.'.str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
    }

    private function requireCredentials(): void
    {
        if ($this->val('api_key') === '' || $this->val('contract_code') === '') {
            throw new FinancialException(
                'MONNIFY_NOT_CONFIGURED',
                'Monnify credentials are not configured (admin dashboard or MONNIFY_API_KEY / MONNIFY_CONTRACT_CODE).',
                503,
            );
        }
    }

    /**
     * OAuth 2.0 client-credentials access token, cached until shortly
     * before expiry.
     */
    public function accessToken(): string
    {
        $this->requireCredentials();

        $apiKey = $this->val('api_key');
        $cacheKey = 'monnify:token:'.substr(hash('sha256', $apiKey), 0, 16);

        $cached = Cache::get($cacheKey);

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::baseUrl($this->val('base_url') !== '' ? $this->val('base_url') : self::DEFAULT_BASE_URL)
            ->asForm()
            ->timeout(15)
            ->post('/api/v2/oauth/token', [
                'grant_type' => 'client_credentials',
                'api_key' => $apiKey,
                'contract_code' => $this->val('contract_code'),
                'secret_key' => $this->val('secret_key'),
            ]);

        $envelope = (array) $response->json();
        $body = (array) ($envelope['responseBody'] ?? []);

        if ($response->failed() || (bool) ($envelope['requestSuccessful'] ?? false) === false || ! isset($body['access_token'])) {
            throw new FinancialException(
                'MONNIFY_TOKEN_ERROR',
                'Unable to obtain a Monnify access token: '.((string) ($envelope['responseMessage'] ?? 'HTTP '.$response->status())),
                502,
            );
        }

        $token = (string) $body['access_token'];
        $ttl = max(30, (int) ($body['expires_in'] ?? 120) - 30);

        Cache::put($cacheKey, $token, now()->addSeconds($ttl));

        return $token;
    }

    /**
     * Perform an authenticated request and return the `responseBody`.
     *
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     *
     * @throws ConnectionException when the request could not be delivered or timed out
     */
    public function request(string $method, string $path, array $body = [], array $query = []): array
    {
        $pending = Http::baseUrl($this->val('base_url') !== '' ? $this->val('base_url') : self::DEFAULT_BASE_URL)
            ->withToken($this->accessToken())
            ->timeout(15)
            ->acceptJson();

        try {
            $response = $method === 'GET'
                ? $pending->withQueryParameters($query)->get($path)
                : $pending->post($path, $body);
        } catch (ConnectionException $e) {
            // Once the request may have been transmitted, the outcome is
            // unknown — let the classifier mark it AMBIGUOUS.
            throw $e;
        }

        $envelope = (array) $response->json();
        $responseBody = (array) ($envelope['responseBody'] ?? []);
        $ok = (bool) ($envelope['requestSuccessful'] ?? false);

        if ($response->failed() || ! $ok) {
            $code = (string) ($envelope['responseCode'] ?? '');
            $message = (string) ($envelope['responseMessage'] ?? '');
            $detail = ($code !== '' ? " ({$code})" : '').($message !== '' ? ": {$message}" : '');

            // 4xx client errors are definitive rejections — no transaction
            // was initiated. 5xx / 2xx-with-error stay ambiguous.
            if ($response->status() >= 400 && $response->status() < 500) {
                throw new ProviderDeclinedException('MONNIFY_API_ERROR', "Monnify rejected the request{$detail}", 422);
            }

            throw new FinancialException(
                'MONNIFY_API_ERROR',
                'Monnify API error'.$detail.($detail === '' ? ' (HTTP '.$response->status().')' : ''),
                502,
            );
        }

        return $responseBody;
    }

    /* --------------------------------------------------------------------
     | Collections (wallet funding)
     * ------------------------------------------------------------------ */

    /**
     * Initialize a one-time payment (bank transfer / card / USSD). The
     * platform reference becomes Monnify's `paymentReference` — it is the
     * join key in webhooks and verification.
     *
     * POST /api/v2/charges/transactions
     *
     * @return array<string, mixed>  reference, paymentUrl, status, bankCode, bankName, accountNumber, ...
     */
    public function initializeTransaction(int $amountKobo, string $reference, string $customerName, ?string $customerEmail, string $paymentDescription): array
    {
        $body = [
            'amount' => self::toNairaString($amountKobo),
            'reference' => $reference,
            'currency' => (string) config('ase.monnify.currency', 'NGN'),
            'customerName' => $customerName,
            'paymentDescription' => $paymentDescription,
            'contractCode' => $this->val('contract_code'),
            'paymentMethods' => ['ACCOUNT_TRANSFER', 'CARD'],
        ];

        if ($customerEmail !== null && $customerEmail !== '') {
            $body['customerEmail'] = $customerEmail;
        }

        return $this->request('POST', '/api/v2/charges/transactions', $body);
    }

    /**
     * Query a collection transaction's status.
     *
     * GET /api/v2/charges/transactions/{transactionReference}
     *
     * @return array<string, mixed>  reference, status (PENDING | PAID | FAILED | EXPIRED), amountPaid, ...
     */
    public function getTransaction(string $transactionReference): array
    {
        return $this->request('GET', '/api/v2/charges/transactions/'.rawurlencode($transactionReference));
    }

    /**
     * Reserve a customer virtual account (persistent, per customer) — the
     * "wallet top-up" rail. Every transfer to the returned account is
     * reconciled to `accountReference`.
     *
     * POST /api/v2/bank-transfer/reserved-accounts
     *
     * @param  array<string, mixed>  $params  accountReference, accountName, customerName, bvn|nin, getAllAvailableBanks, ...
     * @return array<string, mixed>  accountReference, accounts[] (bankCode, bankName, accountNumber), reservationReference, ...
     */
    public function createReservedAccount(array $params): array
    {
        if (! isset($params['contractCode'])) {
            $params['contractCode'] = $this->val('contract_code');
        }

        return $this->request('POST', '/api/v2/bank-transfer/reserved-accounts', $params);
    }

    /**
     * GET /api/v2/bank-transfer/reserved-accounts/{accountReference}
     */
    public function getReservedAccount(string $accountReference): array
    {
        return $this->request('GET', '/api/v2/bank-transfer/reserved-accounts/'.rawurlencode($accountReference));
    }

    /* --------------------------------------------------------------------
     | Disbursements (payouts)
     * ------------------------------------------------------------------ */

    /**
     * Initiate a single transfer (disbursement) to a bank account, funded
     * from the platform's Monnify disbursement wallet
     * (`MONNIFY_SOURCE_ACCOUNT`). `async: true` — the final status arrives
     * via webhook or the status endpoint.
     *
     * POST /api/v2/disbursements/single
     *
     * @return array<string, mixed>  reference, status (PENDING_AUTHORIZATION | SUCCESS | FAILED | ...), totalFee, ...
     */
    public function singleTransfer(int $amountKobo, string $reference, string $bankCode, string $accountNumber, string $accountName, ?string $narration = null): array
    {
        $sourceAccount = $this->val('source_account');

        if ($sourceAccount === '') {
            throw new FinancialException(
                'MONNIFY_NOT_CONFIGURED',
                'Monnify disbursement source account is not configured (admin dashboard or MONNIFY_SOURCE_ACCOUNT).',
                503,
            );
        }

        $body = [
            'amount' => self::toNairaString($amountKobo),
            'reference' => $reference,
            'narration' => (string) ($narration ?? 'Transfer'),
            'destinationBankCode' => $bankCode,
            'destinationAccountNumber' => $accountNumber,
            'destinationAccountName' => $accountName,
            'currency' => (string) config('ase.monnify.currency', 'NGN'),
            'sourceAccountNumber' => $sourceAccount,
            'async' => true,
        ];

        return $this->request('POST', '/api/v2/disbursements/single', $body);
    }

    /**
     * Authorize a PENDING_AUTHORIZATION transfer with the MFA OTP (sent to
     * the Monnify account's registered email).
     *
     * POST /api/v2/disbursements/single/validate-otp
     */
    public function authorizeSingleTransfer(string $reference, string $authorizationCode): array
    {
        return $this->request('POST', '/api/v2/disbursements/single/validate-otp', [
            'reference' => $reference,
            'authorizationCode' => $authorizationCode,
        ]);
    }

    /**
     * Resend the MFA OTP.
     *
     * POST /api/v2/disbursements/single/resend-otp
     */
    public function resendSingleTransferOtp(string $reference): array
    {
        return $this->request('POST', '/api/v2/disbursements/single/resend-otp', [
            'reference' => $reference,
        ]);
    }

    /**
     * Single transfer status.
     *
     * GET /api/v2/disbursements/single/summary?reference={reference}
     *
     * @return array<string, mixed>  status (PENDING | IN_PROGRESS | SUCCESS | FAILED | REVERSED | EXPIRED | ...), transactionReference, ...
     */
    public function getSingleTransferStatus(string $reference): array
    {
        return $this->request('GET', '/api/v2/disbursements/single/summary', [], ['reference' => $reference]);
    }

    /**
     * NIP name enquiry.
     *
     * GET /api/v2/transfer/name-enquiry/{bankCode}/{accountNumber}
     *
     * @return array<string, mixed>  accountName, ...
     */
    public function nameEnquiry(string $bankCode, string $accountNumber): array
    {
        return $this->request('GET', '/api/v2/transfer/name-enquiry/'.rawurlencode($bankCode).'/'.rawurlencode($accountNumber));
    }

    /**
     * Balance of the Monnify disbursement wallet.
     *
     * GET /api/v2/disbursements/wallet-balance?accountNumber={accountNumber}
     */
    public function walletBalance(string $accountNumber): array
    {
        return $this->request('GET', '/api/v2/disbursements/wallet-balance', [], ['accountNumber' => $accountNumber]);
    }
}
