<?php

namespace App\Domain\Notifications\Services;

use App\Models\CustomerNotification;
use App\Models\NotificationDelivery;
use Illuminate\Support\Facades\Log;

/**
 * Customer notification fan-out (SMS / email / push).
 *
 * Delivery is asynchronous in production (queued jobs per channel). This
 * scaffold persists the notification + a delivery row and uses the LOG
 * channel so flows are fully testable.
 */
final class NotificationService
{
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

        return $notification->update(['status' => CustomerNotification::STATUS_SENT]);
    }
}
