<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * In-app notification list, backed by Laravel's native database
 * notification channel (see app/Notifications/V1/*) — "solely for the
 * in-app section", independent of whether the user opted into push.
 */
class UserNotificationController extends Controller
{
    /**
     * GET /v1/user/notifications
     */
    public function index(Request $request): JsonResponse
    {
        $user = $this->authUser($request);

        return response()->json([
            'success' => true,
            'data' => $user->notifications()->paginate(20),
        ]);
    }

    /**
     * POST /v1/user/notifications/mark-all-read
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $this->authUser($request)->unreadNotifications->markAsRead();

        return response()->json(['success' => true, 'message' => 'All notifications marked as read.']);
    }

    /**
     * POST /v1/user/notifications/{id}/mark-read
     */
    public function markRead(Request $request, string $id): JsonResponse
    {
        $notification = $this->authUser($request)->notifications()->where('id', $id)->first();

        if ($notification === null) {
            return response()->json(['success' => false, 'message' => 'Notification not found.'], 404);
        }

        $notification->markAsRead();

        return response()->json(['success' => true, 'message' => 'Notification marked as read.']);
    }

    private function authUser(Request $request): User
    {
        /** @var User $user */
        $user = $request->user('sanctum');

        return $user;
    }
}
