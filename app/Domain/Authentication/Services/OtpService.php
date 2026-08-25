<?php

namespace App\Domain\Authentication\Services;

use App\Exceptions\FinancialException;
use App\Models\OtpChallenge;
use App\Models\User;

/**
 * One-time-passcode issuance and verification.
 *
 * Codes are stored only as SHA-256 hashes, are single-use, expire after a
 * short TTL and have a bounded number of attempts.
 *
 * The "delivery" of the code is pluggable: this scaffold logs it and, in
 * local/testing environments, returns it to the caller so the flow can be
 * exercised end to end without an SMS gateway.
 */
final class OtpService
{
    /**
     * @return array{challenge: OtpChallenge, code: string}
     */
    public function issue(User $user, string $purpose): array
    {
        OtpChallenge::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->whereNull('consumed_at')
            ->delete();

        $length = (int) config('ase.otp.length', 6);
        $code = (string) random_int((int) 10 ** ($length - 1), (int) 10 ** $length - 1);

        $challenge = OtpChallenge::create([
            'user_id' => $user->id,
            'purpose' => $purpose,
            'code_hash' => hash('sha256', $code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes((int) config('ase.otp.ttl_minutes', 10)),
        ]);

        // Production: dispatch to the SMS gateway here.
        if (app()->environment('local', 'testing')) {
            \Illuminate\Support\Facades\Log::info("OTP for user {$user->id} ({$purpose}): {$code}");
        }

        return ['challenge' => $challenge, 'code' => $code];
    }

    /**
     * @throws FinancialException
     */
    public function verify(OtpChallenge $challenge, string $code): void
    {
        if ($challenge->consumed_at !== null) {
            throw new FinancialException('OTP_EXPIRED', 'This code has already been used.', 422);
        }

        if ($challenge->expires_at->isPast()) {
            throw new FinancialException('OTP_EXPIRED', 'This code has expired. Request a new one.', 422);
        }

        $maxAttempts = (int) config('ase.otp.max_attempts', 5);

        if ($challenge->attempts >= $maxAttempts) {
            throw new FinancialException('OTP_LOCKED', 'Too many incorrect attempts. Request a new code.', 429);
        }

        if (! hash_equals($challenge->code_hash, hash('sha256', $code))) {
            $challenge->update(['attempts' => $challenge->attempts + 1]);

            throw new FinancialException('OTP_INVALID', 'Invalid code.', 422);
        }

        $challenge->update(['consumed_at' => now()]);
    }

    public function latestChallenge(User $user, string $purpose): ?OtpChallenge
    {
        return OtpChallenge::where('user_id', $user->id)
            ->where('purpose', $purpose)
            ->latest('id')
            ->first();
    }
}
