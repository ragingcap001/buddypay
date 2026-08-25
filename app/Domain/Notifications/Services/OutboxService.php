<?php

namespace App\Domain\Notifications\Services;

use App\Models\OutboxEvent;

/**
 * Transactional outbox.
 *
 * Outbox events are recorded inside the SAME database transaction as the
 * financial state change they describe. The outbox publisher then delivers
 * them (and marks them dispatched), which guarantees events are never lost
 * if a process dies after the database commit.
 */
final class OutboxService
{
    public const STATUS_PENDING = 'PENDING';
    public const STATUS_DISPATCHED = 'DISPATCHED';
    public const STATUS_FAILED = 'FAILED';

    public const MAX_ATTEMPTS = 5;

    /**
     * Record an event. Must be called inside the active database
     * transaction — do not commit separately.
     */
    public function record(string $aggregateType, int $aggregateId, string $eventType, array $payload): OutboxEvent
    {
        return OutboxEvent::create([
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => $payload,
            'status' => self::STATUS_PENDING,
            'attempts' => 0,
        ]);
    }
}
