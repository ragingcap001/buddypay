<?php

namespace App\Console\Commands;

use App\Domain\Reconciliation\Services\ReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class ReconcileProvider extends Command
{
    protected $signature = 'reconciliation:run
                            {provider : Provider name (e.g. mock)}
                            {--days=1 : Look back N days}
                            {--from= : Explicit start date (Y-m-d) instead of --days}';

    protected $description = 'Reconcile internal transactions against provider records for a window';

    public function handle(ReconciliationService $reconciliation): int
    {
        $to = now()->endOfDay();
        $from = $this->argument('from')
            ? Carbon::parse($this->argument('from'))->startOfDay()
            : $to->copy()->subDays((int) $this->option('days'))->startOfDay();

        $batch = $reconciliation->runBatch((string) $this->argument('provider'), $from, $to);

        $this->table(
            ['Batch', 'Provider', 'Status', 'Items', 'Matched', 'Exceptions'],
            [[
                $batch->id,
                $batch->provider_name,
                $batch->status,
                $batch->total_items,
                $batch->matched,
                $batch->exceptions,
            ]],
        );

        return $batch->status === 'COMPLETED' ? self::SUCCESS : self::FAILURE;
    }
}
