<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Config\Services\AppConfigService;
use App\Domain\Notifications\Services\FcmService;
use App\Http\Controllers\Controller;
use App\Http\Support\ApiResponse;
use App\Models\PushDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Push-notification admin endpoints.
 *
 * - POST /api/v1/admin/push/test — send a test push (to a specific device
 *   token, or to one random registered device when none is given).
 * - GET  /api/v1/admin/push/devices — registered device overview.
 */
class AdminPushController extends Controller
{
    public function __construct(
        private readonly FcmService $push,
        private readonly AppConfigService $appConfig,
    ) {
    }

    public function test(Request $request): JsonResponse
    {
        $token = (string) $request->input('device_token', '');

        if ($token === '') {
            $device = PushDevice::where('active', true)->latest('last_used_at')->first()
                ?? PushDevice::where('active', true)->first();

            if ($device === null) {
                return ApiResponse::error('NO_DEVICES', 'No push devices are registered yet.', 404, $request);
            }

            $token = (string) $device->token;
        }

        if (! $this->push->isConfigured()) {
            return ApiResponse::error(
                'FCM_NOT_CONFIGURED',
                'Firebase is not configured yet — set project_id and the service account JSON in the Firebase group.',
                503,
                $request,
            );
        }

        $result = $this->push->send($token, 'Aṣẹ test push', 'This is a test notification from the admin dashboard.', [
            'test' => '1',
        ]);

        if (! $result['ok']) {
            return ApiResponse::error('PUSH_FAILED', 'FCM rejected the test message: '.$result['error'], 502, $request);
        }

        return ApiResponse::success(['message_id' => $result['message_id']], 'Test push sent.');
    }

    public function devices(): JsonResponse
    {
        $devices = PushDevice::with('user:id,name,phone')
            ->latest('created_at')
            ->limit(200)
            ->get()
            ->map(fn (PushDevice $device): array => [
                'id' => $device->id,
                'user' => $device->user?->name ?? '—',
                'phone' => $device->user?->phone,
                'platform' => $device->platform,
                'name' => $device->name,
                'active' => $device->active,
                'last_used_at' => $device->last_used_at?->toIso8601String(),
                'created_at' => $device->created_at?->toIso8601String(),
            ]);

        $configured = $this->push->isConfigured();

        return ApiResponse::success([
            'firebase_configured' => $configured,
            'total' => $devices->count(),
            'devices' => $devices,
        ]);
    }
}
