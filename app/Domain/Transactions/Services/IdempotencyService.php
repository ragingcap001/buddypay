<?php

namespace App\Domain\Transactions\Services;

use App\Exceptions\IdempotencyKeyReusedException;
use App\Exceptions\RequestInProcessException;
use App\Models\IdempotencyKey;

/**
 * Idempotency for financial mutation endpoints.
 *
 * Stored per (user, key): the request hash, the stored response, the linked
 * transaction reference and a status.
 *
 *  - First request      -> key recorded as IN_PROGRESS, request proceeds.
 *  - Retry (completed)  -> the original response is returned unchanged.
 *  - Retry (in-flight)  -> REQUEST_IN_PROGRESS (409), client should retry.
 *  - Same key, different body -> IDEMPOTENCY_KEY_REUSED (409), rejected.
 */
final class IdempotencyService
{
    /**
     * Begin an idempotent operation.
     *
     * @return array{idempotency: IdempotencyKey, storedResponse: ?array}
     *               storedResponse is non-null when this is a replay of a
     *               completed request and the caller must short-circuit.
     */
    public function begin(int $userId, string $key, string $requestHash): array
    {
        $existing = IdempotencyKey::where('user_id', $userId)
            ->where('key', $key)
            ->first();

        if ($existing !== null) {
            if ($existing->request_hash !== $requestHash) {
                throw new IdempotencyKeyReusedException();
            }

            if ($existing->status === IdempotencyKey::STATUS_COMPLETED && $existing->response !== null) {
                return [
                    'idempotency' => $existing,
                    'storedResponse' => $existing->response,
                ];
            }

            throw new RequestInProcessException();
        }

        $created = IdempotencyKey::create([
            'user_id' => $userId,
            'key' => $key,
            'request_hash' => $requestHash,
            'status' => IdempotencyKey::STATUS_IN_PROGRESS,
        ]);

        return [
            'idempotency' => $created,
            'storedResponse' => null,
        ];
    }

    /**
     * Record the final response for an in-progress idempotency key.
     */
    public function complete(IdempotencyKey $key, array $response, ?string $transactionReference = null): void
    {
        $key->update([
            'status' => IdempotencyKey::STATUS_COMPLETED,
            'response' => $response,
            'transaction_reference' => $transactionReference,
        ]);
    }

    /**
     * Compute a stable hash for the request identity.
     */
    public function hashRequest(string $method, string $path, array $body, int $userId): string
    {
        ksort($body);

        return hash('sha256', implode('|', [
            strtoupper($method),
            $path,
            json_encode($body, JSON_THROW_ON_ERROR),
            (string) $userId,
        ]));
    }
}
