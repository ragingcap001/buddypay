<?php

namespace App\Console\Commands;

use App\Domain\Wallet\Services\WalletService;
use Illuminate\Console\Command;

class ExpireWalletReservations extends Command
{
    protected $signature = 'wallets:expire-reservations
                            {--batch=200 : Number of reservations to consider}';

    protected $description = 'Release expired active wallet reservations so funds return to the available balance';

    public function handle(WalletService $wallets): int
    {
        $count = $wallets->expireStale((int) $this->option('batch'));

        if ($count > 0) {
            $this->info("Released {$count} expired reservation(s).");
        }

        return self::SUCCESS;
    }
}
