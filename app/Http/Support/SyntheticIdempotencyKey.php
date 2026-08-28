<?php

namespace App\Http\Support;

/**
 * Derives an idempotency key from the request itself, for the mobile
 * contract's purchase endpoints — none of which send an Idempotency-Key
 * header (unlike the internal /v1/bills/pay, /v1/wallet/fund routes).
 *
 * A repeated identical tap (the double-submit this exists to prevent)
 * produces the same body and therefore the same key, so IdempotencyService
 * still short-circuits it to the original result. A genuinely different
 * purchase — even seconds later — hashes differently and proceeds
 * normally. No client cooperation required.
 */
final class SyntheticIdempotencyKey
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public static function forRequest(int $userId, string $routeName, array $payload): string
    {
        ksort($payload);

        return hash('sha256', implode('|', [
            $routeName,
            (string) $userId,
            json_encode($payload, JSON_THROW_ON_ERROR),
        ]));
    }
}
