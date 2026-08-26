<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Support\ApiResponse;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Push-device registration (used by the mobile clients). Delivery itself
 * goes through FCM, so Android (Google) and iOS (Apple) tokens share this
 * endpoint — the platform field is informational.
 *
 * - POST   /api/v1/notifications/devices   { platform, token, name? }
 * - GET    /api/v1/notifications/devices
 * - DELETE /api/v1/notifications/devices/{token}
 */
class NotificationDeviceController extends Controller
{
    private const PLATFORMS = ['android', 'ios', 'web'];

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'platform' => ['required', 'string', \Illuminate\Validation\Rule::in(self::PLATFORMS)],
            'token' => ['required', 'string', 'min:20', 'max:255'],
            'name' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $user = /** @var User */ $request->user('sanctum');

        $device = $user->pushDevices()->firstOrCreate(
            ['token' => $validated['token']],
            [
                'platform' => $validated['platform'],
                'name' => $validated['name'] ?? null,
                'active' => true,
            ],
        );

        if ($device->platform !== $validated['platform'] || $device->name !== ($validated['name'] ?? null)) {
            $device->update([
                'platform' => $validated['platform'],
                'name' => $validated['name'] ?? null,
                'active' => true,
            ]);
        }

        return ApiResponse::success([
            'id' => $device->id,
            'platform' => $device->platform,
            'name' => $device->name,
            'active' => $device->active,
        ], 'Push device registered.', $device->wasRecentlyCreated ? 201 : 200);
    }

    public function index(Request $request): JsonResponse
    {
        $user = /** @var User */ $request->user('sanctum');

        $devices = $user->pushDevices()->get()
            ->map(fn ($device): array => [
                'id' => $device->id,
                'platform' => $device->platform,
                'name' => $device->name,
                'active' => $device->active,
                'last_used_at' => $device->last_used_at?->toIso8601String(),
            ]);

        return ApiResponse::success(['devices' => $devices]);
    }

    public function destroy(Request $request, string $token): JsonResponse
    {
        $user = /** @var User */ $request->user('sanctum');

        $deleted = $user->pushDevices()->where('token', $token)->delete();

        if ($deleted === 0) {
            return ApiResponse::error('DEVICE_NOT_FOUND', 'This device is not registered to your account.', 404, $request);
        }

        return ApiResponse::success(null, 'Push device unregistered.');
    }
}
