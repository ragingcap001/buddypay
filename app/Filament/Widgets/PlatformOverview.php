<?php

namespace App\Filament\Widgets;

use App\Domain\Transactions\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Models\User;
use App\Models\Wallet;
use Filament\Widgets\StatsOverviewWidget as BaseWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class PlatformOverview extends BaseWidget
{
    protected function getStats(): array
    {
        $today = today();

        $completedToday = Transaction::where('status', TransactionStatus::Completed->value)
            ->whereDate('created_at', $today);

        $transactionsToday = (clone $completedToday)->count();
        $volumeToday = (clone $completedToday)->sum('amount');
        $revenueToday = (clone $completedToday)->sum('fee');

        $failedToday = Transaction::where('status', TransactionStatus::Failed->value)
            ->whereDate('created_at', $today)
            ->count();

        return [
            Stat::make('Total users', number_format(User::count())),

            Stat::make('Wallet float', $this->naira(Wallet::sum('control_balance')))
                ->description('Total held for customers'),

            Stat::make('Transactions today', number_format($transactionsToday))
                ->description($this->naira($volumeToday).' moved'),

            Stat::make('Revenue today', $this->naira($revenueToday))
                ->description('Fees earned'),

            Stat::make('Failed today', number_format($failedToday))
                ->color($failedToday > 0 ? 'danger' : 'success'),
        ];
    }

    private function naira(int|string|null $kobo): string
    {
        return '₦'.number_format(((int) $kobo) / 100, 2);
    }
}
