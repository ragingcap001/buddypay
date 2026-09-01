<?php

namespace App\Http\Support;

/**
 * Derives an idempotency key from the request itself, for the mobile
 * contract's purchase endpoints — none of which send an Idempotency-Key
 * header (unlike the internal /v1/bills/pay, /v1/wallet/fund routes).
 *
 * A repeated identical tap (the double-submit this exists to prevent)
 * produces the same body and therefore the same key within the same time
 * window, so IdempotencyService still short-circuits it to the original
 * result. The window matters: without one, buying the exact same ₦1,000
 * MTN airtime again tomorrow hashes identically to yesterday's purchase
 * and IdempotencyService would replay yesterday's completed transaction
 * forever instead of ever charging again — a request that is content-wise
 * identical is not necessarily the same *operation* days (or even
 * minutes) later. Bucketing time into the key means two requests fold
 * together only when they're both identical AND close together; the same
 * purchase made again after the window closes gets a fresh key and
 * genuinely re-executes. No client cooperation required either way.
 */
final class SyntheticIdempotencyKey
{
    /**
     * A double-tap lands within this window in practice; a deliberate
     * repeat (even a fast one) is a different operation and should not
     * collapse into the first. This does not depend on IdempotencyKey
     * rows ever being cleaned up — a new bucket is simply a new key.
     */
    private const WINDOW_SECONDS = 10;

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
            (string) intdiv(time(), self::WINDOW_SECONDS),
        ]));
    }
}
