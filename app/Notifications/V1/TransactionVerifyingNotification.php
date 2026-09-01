<?php

namespace App\Notifications\V1;

use App\Models\Transaction;
use Illuminate\Notifications\Notification;

/**
 * Sent for the AMBIGUOUS state — the provider's outcome could not be
 * classified as definitive success or failure, so it's being reconciled.
 * Deliberately not phrased as failure: the customer's money has not been
 * lost, and reconciliation may still resolve it as a success.
 */
class TransactionVerifyingNotification extends Notification
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
            'title' => 'Transaction Being Verified',
            'message' => 'Your purchase of ₦'.number_format($naira)." for {$service} is being verified with the provider. We'll update you shortly.",
            'type' => 'transaction_verifying',
            'data' => [
                'transaction_id' => $this->transaction->id,
                'trans_id' => $this->transaction->reference,
                'amount' => $naira,
                'service' => $service,
            ],
        ];
    }
}
