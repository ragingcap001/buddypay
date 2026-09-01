<?php

namespace App\Filament\Widgets;

use App\Domain\Transactions\Enums\TransactionStatus;
use App\Models\Transaction;
use Filament\Widgets\ChartWidget;

class TransactionTrendChart extends ChartWidget
{
    protected ?string $heading = 'Completed transactions (last 14 days)';

    protected function getData(): array
    {
        $days = collect(range(13, 0))->map(fn (int $offset) => today()->subDays($offset));

        $counts = Transaction::where('status', TransactionStatus::Completed->value)
            ->where('created_at', '>=', today()->subDays(13))
            ->selectRaw('DATE(created_at) as day, COUNT(*) as count')
            ->groupBy('day')
            ->pluck('count', 'day');

        $data = $days->map(fn ($day) => (int) ($counts[$day->toDateString()] ?? 0));

        return [
            'datasets' => [
                [
                    'label' => 'Transactions',
                    'data' => $data->values(),
                ],
            ],
            'labels' => $days->map(fn ($day) => $day->format('M j'))->values(),
        ];
    }

    protected function getType(): string
    {
        return 'line';
    }
}
