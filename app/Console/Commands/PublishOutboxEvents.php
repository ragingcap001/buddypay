<?php

namespace App\Console\Commands;

use App\Infrastructure\Messaging\OutboxPublisher;
use Illuminate\Console\Command;

class PublishOutboxEvents extends Command
{
    protected $signature = 'outbox:publish {--batch=100 : Number of events to publish}';

    protected $description = 'Dispatch pending transactional-outbox events (notifications, downstream processing)';

    public function handle(OutboxPublisher $publisher): int
    {
        $count = $publisher->publish((int) $this->option('batch'));

        if ($count > 0) {
            $this->info("Published {$count} outbox event(s).");
        }

        return self::SUCCESS;
    }
}
