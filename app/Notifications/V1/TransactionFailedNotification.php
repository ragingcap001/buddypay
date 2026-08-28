<?php

namespace App\Notifications\V1;

use App\Models\Transaction;
use Illuminate\Notifications\Notification;

class TransactionFailedNotification extends Notification
{
    public function __construct(private readonly Transaction $transaction)
    {
    }

    /**
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        return ['database'];
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(mixed $notifiable): array
    {
        $naira = intdiv((int) $this->transaction->amount, 100);
        $service = TransactionServiceName::forNotification($this->transaction);

        return [
            'title' => 'Transaction Failed',
            'message' => 'Your purchase of ₦'.number_format($naira)." for {$service} could not be completed. Any reserved funds have been returned to your wallet.",
            'type' => 'transaction_failed',
            'data' => [
                'transaction_id' => $this->transaction->id,
                'trans_id' => $this->transaction->reference,
                'amount' => $naira,
                'service' => $service,
            ],
        ];
    }
}
