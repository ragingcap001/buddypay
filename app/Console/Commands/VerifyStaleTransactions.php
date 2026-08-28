<?php

namespace App\Console\Commands;

use App\Domain\GiftCards\Services\GiftCardPurchaseService;
use App\Domain\Payments\Services\FundingService;
use App\Domain\Payments\Services\PayoutService;
use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Enums\TransactionType;
use App\Domain\Transactions\Services\BillPaymentService;
use App\Models\Transaction;
use Illuminate\Console\Command;
use Throwable;

class VerifyStaleTransactions extends Command
{
    protected $signature = 'transactions:verify-stale
                            {--minutes=2 : Verify transactions in VERIFYING for at least N minutes}';

    protected $description = 'Drive AMBIGUOUS/VERIFYING transactions to a definite outcome by asking the original provider (never failover)';

    public function handle(BillPaymentService $billPayments, FundingService $funding, PayoutService $payouts, GiftCardPurchaseService $giftCards): int
    {
        $since = now()->subMinutes((int) $this->option('minutes'));

        $stale = Transaction::where('status', TransactionStatus::Verifying->value)
            ->where('updated_at', '<=', $since)
            ->limit(100)
            ->get();

        $resolved = 0;

        foreach ($stale as $transaction) {
            try {
                if ($transaction->type === TransactionType::WalletFunding->value) {
                    $fresh = $funding->verifyReference($transaction);
                } elseif ($transaction->type === TransactionType::BankTransfer->value) {
                    $fresh = $payouts->verifyReference($transaction);
                } elseif ($transaction->type === TransactionType::GiftCard->value) {
                    $fresh = $giftCards->verify($transaction->reference);
                } else {
                    $fresh = $billPayments->verify($transaction->reference);
                }

                if (TransactionStatus::from($fresh->status)->isTerminal()) {
                    $resolved++;
                    $this->info("Resolved {$transaction->reference} -> {$fresh->status}");
                }
            } catch (Throwable $e) {
                $this->warn("Could not resolve {$transaction->reference}: {$e->getMessage()}");
            }
        }

        $this->info("Checked {$stale->count()} stale transaction(s), resolved {$resolved}.");

        return self::SUCCESS;
    }
}
