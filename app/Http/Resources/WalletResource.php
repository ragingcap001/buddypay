<?php

namespace App\Http\Resources;

use App\Models\Wallet;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Wallet
 */
class WalletResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $available = $this->availableBalance();

        return [
            'id' => $this->id,
            'currency' => $this->currency,
            'control_balance' => (int) $this->control_balance,
            'reserved_balance' => (int) $this->reserved_balance,
            'available_balance' => $available,
            'available_balance_formatted' => \App\Domain\Wallet\ValueObjects\Money::naira($available)->toMajorUnitsString(),
        ];
    }
}
