<?php

namespace App\Notifications\V1;

use App\Models\Transaction;
use Illuminate\Notifications\Notification;

/**
 * In-app (database channel) record of a completed transaction. This is
 * deliberately separate from NotificationService's SMS/push/log delivery
 * pipeline — this is "solely for the in-app notification section", per
 * the mobile contract, so it must exist even for a user who never opted
 * into push.
 */
class TransactionSuccessNotification extends Notification
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
            'title' => 'Transaction Successful',
            'message' => 'Your purchase of ₦'.number_format($naira)." for {$service} was successful.",
            'type' => 'transaction_success',
            'data' => [
                'transaction_id' => $this->transaction->id,
                'trans_id' => $this->transaction->reference,
                'amount' => $naira,
                'service' => $service,
            ],
        ];
    }
}
