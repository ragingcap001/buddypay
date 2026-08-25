<?php

namespace App\Infrastructure\Messaging\Events;

use App\Models\OutboxEvent;

class OutboxEventDispatched
{
    public function __construct(public readonly OutboxEvent $event)
    {
    }
}
