<?php

namespace App\Http\Resources;

use App\Domain\Wallet\ValueObjects\Money;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $amount = Money::naira((int) $this->amount);
        $fee = Money::naira((int) $this->fee);

        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'type' => $this->type,
            'status' => $this->status,
            'amount' => (int) $this->amount,
            'amount_formatted' => $amount->toMajorUnitsString(),
            'fee' => (int) $this->fee,
            'fee_formatted' => $fee->toMajorUnitsString(),
            'currency' => $this->currency,
            'provider' => $this->provider,
            'provider_reference' => $this->provider_reference,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
        ];
    }
}
