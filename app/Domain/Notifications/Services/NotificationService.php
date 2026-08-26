<?php

namespace App\Domain\Notifications\Services;

use App\Models\CustomerNotification;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Log;

/**
 * Customer notification fan-out (SMS / email / push).
 *
 * Delivery is asynchronous in production (queued jobs per channel). This
 * scaffold persists the notification + a delivery row, uses the LOG channel
 * so flows are fully testable, and — when Firebase is configured and the
 * user has registered devices — delivers via FCM push (Android and iOS).
 */
final class NotificationService
{
    public function __construct(private readonly FcmService $push)
    {
    }

    public function send(int $userId, string $type, string $title, string $body): CustomerNotification
    {
        $notification = CustomerNotification::create([
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'channel' => 'LOG',
            'status' => CustomerNotification::STATUS_PENDING,
        ]);

        $delivery = NotificationDelivery::create([
            'notification_id' => $notification->id,
            'channel' => 'LOG',
            'status' => CustomerNotification::STATUS_PENDING,
        ]);

        // Stub delivery: structured log line (no sensitive data).
        Log::info('customer_notification', [
            'event' => $type,
            'user_id' => $userId,
            'title' => $title,
        ]);

        $delivery->update([
            'status' => CustomerNotification::STATUS_SENT,
            'sent_at' => now(),
        ]);

        // Real delivery: push to registered devices when FCM is configured.
        $this->deliverPush($notification, $userId, $title, $body);

        return $notification->update(['status' => CustomerNotification::STATUS_SENT]);
    }

    private function deliverPush(CustomerNotification $notification, int $userId, string $title, string $body): void
    {
        $user = \App\Models\User::find($userId);

        if ($user === null) {
            return;
        }

        $hasDevices = $user->pushDevices()->where('active', true)->exists();

        if (! $hasDevices || ! $this->push->isConfigured()) {
            return; // nothing to do — LOG row already recorded above
        }

        $delivery = NotificationDelivery::create([
            'notification_id' => $notification->id,
            'channel' => 'PUSH',
            'status' => CustomerNotification::STATUS_PENDING,
        ]);

        try {
            $result = $this->push->sendToUser($user, $title, $body, [
                'notification_id' => (string) $notification->id,
                'type' => $type,
            ]);

            $status = $result['sent'] > 0
                ? CustomerNotification::STATUS_SENT
                : CustomerNotification::STATUS_FAILED;

            $delivery->update([
                'status' => $status,
                'sent_at' => now(),
                'error' => $result['sent'] > 0 ? null : 'no active devices acknowledged the push',
            ]);
        } catch (\Throwable $e) {
            // Push failure never breaks the notification flow — the LOG
            // channel row is the record; the PUSH row captures the error.
            $delivery->update([
                'status' => CustomerNotification::STATUS_FAILED,
                'error' => $e->getMessage(),
            ]);

            Log::warning('Push delivery failed', ['notification' => $notification->id, 'error' => $e->getMessage()]);
        }
    }
}
