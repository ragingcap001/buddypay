<?php

namespace App\Domain\Providers\Services;

use App\Domain\Providers\Enums\ProviderOutcome;
use App\Exceptions\ProviderDeclinedException;
use App\Exceptions\ProviderTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Throwable;

/**
 * Classifies provider responses into:
 *
 *  - DEFINITIVE_SUCCESS  — the provider confirmed completion.
 *  - DEFINITIVE_FAILURE  — the provider confirmed the transaction did not
 *                          complete (safe to refund/retry elsewhere).
 *  - AMBIGUOUS           — the outcome cannot be safely determined (timeout,
 *                          connection reset, 5xx, unknown response).
 *                          Ambiguous outcomes must be VERIFIED, never
 *                          blindly failed over, to avoid duplicate charges.
 */
final class OutcomeClassifier
{
    /**
     * Classify a raw HTTP-style response.
     *
     * @param  array{status_code?: int, success?: bool, body?: array<string, mixed>}  $response
     */
    public function classify(array $response): ProviderOutcome
    {
        $statusCode = (int) ($response['status_code'] ?? 0);
        $success = (bool) ($response['success'] ?? false);

        if ($statusCode >= 200 && $statusCode < 300) {
            return $success ? ProviderOutcome::DefinitiveSuccess : ProviderOutcome::Ambiguous;
        }

        if ($statusCode >= 400 && $statusCode < 500) {
            return ProviderOutcome::DefinitiveFailure;
        }

        // 5xx, 0xx (no response) and anything unknown are ambiguous.
        return ProviderOutcome::Ambiguous;
    }

    /**
     * Classify an exception thrown during a provider call.
     *
     * Once the request may have been transmitted, ANY failure is ambiguous
     * until the provider's own verification endpoint says otherwise.
     */
    public function classifyException(Throwable $e): ProviderOutcome
    {
        // The provider rejected the request before initiating anything —
        // no external transaction may exist, so failing it is safe.
        if ($e instanceof ProviderDeclinedException) {
            return ProviderOutcome::DefinitiveFailure;
        }

        if ($e instanceof ProviderTimeoutException) {
            return ProviderOutcome::Ambiguous;
        }

        if ($e instanceof ConnectionException) {
            return ProviderOutcome::Ambiguous;
        }

        return ProviderOutcome::Ambiguous;
    }
}
