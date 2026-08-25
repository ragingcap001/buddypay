<?php

namespace App\Domain\Authentication\Services;

use App\Models\User;
use App\Models\UserDevice;
use Illuminate\Http\Request;

/**
 * Device fingerprinting and tracking for account-security purposes.
 */
final class DeviceService
{
    public function recordLogin(User $user, ?Request $request = null): UserDevice
    {
        $userAgent = $request?->userAgent();
        $ip = $request?->ip();
        $fingerprint = hash('sha256', $userAgent.'|'.$ip);

        $device = UserDevice::where('user_id', $user->id)
            ->where('fingerprint', $fingerprint)
            ->first();

        $now = now();

        if ($device === null) {
            $device = UserDevice::create([
                'user_id' => $user->id,
                'fingerprint' => $fingerprint,
                'user_agent' => $userAgent,
                'ip_address' => $ip,
                'trusted' => false,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
            ]);
        } else {
            $device->update(['last_seen_at' => $now]);
        }

        return $device;
    }
}
