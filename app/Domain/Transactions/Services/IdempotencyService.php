<?php

namespace App\Domain\Transactions\Services;

use App\Exceptions\IdempotencyKeyReusedException;
use App\Exceptions\RequestInProcessException;
use App\Models\IdempotencyKey;
use Illuminate\Support\Facades\DB;

/**
 * Idempotency for financial mutation endpoints.
 *
 * Stored per (user, key): the request hash, the stored response, the linked
 * transaction reference and a status.
 *
 *  - First request      -> key recorded as IN_PROGRESS, request proceeds.
 *  - Retry (completed, not yet expired) -> the original response is
 *    returned unchanged.
 *  - Retry (completed, past ttl_days)   -> treated as a fresh operation —
 *    a genuinely new attempt with the same content (e.g. the same repeat
 *    purchase made again days later) must not replay a stale result
 *    forever just because the key happens to collide.
 *  - Retry (in-flight, within the grace period) -> REQUEST_IN_PROGRESS
 *    (409), client should retry.
 *  - Retry (in-flight, past the grace period)   -> presumed abandoned
 *    (crash/unhandled exception between begin() and complete(), which
 *    never reaches a terminal state on its own) — treated as a fresh
 *    attempt rather than a 409 with no way out.
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

            $expired = $existing->expires_at !== null && $existing->expires_at->isPast();

            if ($existing->status === IdempotencyKey::STATUS_COMPLETED) {
                if (! $expired && $existing->response !== null) {
                    return [
                        'idempotency' => $existing,
                        'storedResponse' => $existing->response,
                    ];
                }
                // Past its replay window — fall through and restart it below.
            } elseif (! $expired) {
                throw new RequestInProcessException();
            }

            // IN_PROGRESS past the grace period (presumed abandoned) or
            // COMPLETED past its replay window: reviving the row races
            // against another request doing the same thing at the same
            // instant — lock it and re-check inside the transaction before
            // committing to a restart, so at most one caller ever proceeds.
            return DB::transaction(function () use ($userId, $key, $requestHash): array {
                $locked = IdempotencyKey::where('user_id', $userId)
                    ->where('key', $key)
                    ->lockForUpdate()
                    ->first();

                if ($locked === null) {
                    // Deleted by a sweeper between the two reads — proceed
                    // as a fresh key.
                    return $this->create($userId, $key, $requestHash);
                }

                $stillExpired = $locked->expires_at !== null && $locked->expires_at->isPast();

                if (! $stillExpired) {
                    // Another request already revived (or completed) it
                    // first — re-evaluate against what it left behind.
                    if ($locked->status === IdempotencyKey::STATUS_COMPLETED && $locked->response !== null) {
                        return ['idempotency' => $locked, 'storedResponse' => $locked->response];
                    }

                    throw new RequestInProcessException();
                }

                $locked->update([
                    'status' => IdempotencyKey::STATUS_IN_PROGRESS,
                    'response' => null,
                    'transaction_reference' => null,
                    'expires_at' => $this->inProgressExpiry(),
                ]);

                return ['idempotency' => $locked, 'storedResponse' => null];
            });
        }

        return $this->create($userId, $key, $requestHash);
    }

    /**
     * @return array{idempotency: IdempotencyKey, storedResponse: null}
     */
    private function create(int $userId, string $key, string $requestHash): array
    {
        $created = IdempotencyKey::create([
            'user_id' => $userId,
            'key' => $key,
            'request_hash' => $requestHash,
            'status' => IdempotencyKey::STATUS_IN_PROGRESS,
            'expires_at' => $this->inProgressExpiry(),
        ]);

        return [
            'idempotency' => $created,
            'storedResponse' => null,
        ];
    }

    /**
     * Record the final response for an in-progress idempotency key. The
     * short "still in flight" grace window is replaced with the long
     * replay-cache ttl now that there is a real result to cache.
     */
    public function complete(IdempotencyKey $key, array $response, ?string $transactionReference = null): void
    {
        $key->update([
            'status' => IdempotencyKey::STATUS_COMPLETED,
            'response' => $response,
            'transaction_reference' => $transactionReference,
            'expires_at' => now()->addDays((int) config('ase.idempotency.ttl_days', 30)),
        ]);
    }

    private function inProgressExpiry(): \Illuminate\Support\Carbon
    {
        return now()->addMinutes((int) config('ase.idempotency.stuck_in_progress_minutes', 5));
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
