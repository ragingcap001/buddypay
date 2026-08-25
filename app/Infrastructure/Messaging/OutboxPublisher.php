<?php

namespace App\Infrastructure\Messaging;

use App\Domain\Notifications\Services\OutboxService;
use App\Infrastructure\Messaging\Events\OutboxEventDispatched;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Delivers pending outbox events.
 *
 * Runs on a schedule (and may also be triggered manually). Events are
 * locked, dispatched as a Laravel event (listeners push downstream work
 * onto queues), and marked dispatched. Failures are retried up to
 * OutboxService::MAX_ATTEMPTS before the event is marked FAILED for
 * manual intervention.
 */
final class OutboxPublisher
{
    public function publish(int $batchSize = 100): int
    {
        return DB::transaction(function () use ($batchSize): int {
            $events = OutboxEvent::where('status', OutboxService::STATUS_PENDING)
                ->orderBy('id')
                ->limit($batchSize)
                ->lockForUpdate()
                ->get();

            $published = 0;

            foreach ($events as $event) {
                try {
                    event(new OutboxEventDispatched($event));

                    $event->update([
                        'status' => OutboxService::STATUS_DISPATCHED,
                        'dispatched_at' => now(),
                    ]);

                    $published++;
                } catch (Throwable $e) {
                    $attempts = (int) $event->attempts + 1;

                    $event->update([
                        'status' => $attempts >= OutboxService::MAX_ATTEMPTS
                            ? OutboxService::STATUS_FAILED
                            : OutboxService::STATUS_PENDING,
                        'attempts' => $attempts,
                    ]);

                    Log::error("Outbox event [{$event->id}] ({$event->event_type}) dispatch failed: {$e->getMessage()}");
                }
            }

            return $published;
        });
    }
}
