<?php

namespace App\Domain\Authentication\Services;

use App\Domain\Audit\Services\AuditService;
use App\Exceptions\FinancialException;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * Transaction PIN management.
 *
 * PINs are bcrypt-hashed, verified with Hash::check, and PIN verification
 * endpoints are rate limited by the API. Changing the PIN requires a
 * successful password (or existing PIN) check — enforced at the
 * controller layer.
 */
final class PinService
{
    public function __construct(private readonly AuditService $audit)
    {
    }

    public function setPin(User $user, string $pin): void
    {
        $user->update(['pin_hash' => Hash::make($pin)]);

        $this->audit->log('security.pin_changed', $user, $user);
    }

    public function verify(User $user, string $pin): bool
    {
        if ($user->pin_hash === null) {
            throw new FinancialException('PIN_NOT_SET', 'No PIN is set on this account.', 400);
        }

        return Hash::check($pin, $user->pin_hash);
    }

    public function hasPin(User $user): bool
    {
        return $user->pin_hash !== null;
    }
}
