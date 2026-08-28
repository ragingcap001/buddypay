<?php

namespace App\Http\Resources;

use App\Domain\Transactions\Enums\TransactionStatus;
use App\Domain\Transactions\Enums\TransactionType;
use App\Domain\Transactions\Support\MobileTransactionStatus;
use App\Domain\Transactions\Support\MobileTransactionType;
use App\Domain\Wallet\ValueObjects\Money;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class MobileTransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = (array) $this->metadata;
        $type = TransactionType::from($this->type);

        $row = [
            'id' => $this->id,
            'type' => MobileTransactionType::forDisplay($type),
            'serviceName' => (string) ($metadata['service_name'] ?? ucfirst(strtolower($this->type))),
            'transId' => $this->reference,
            'beneficiary' => (string) ($metadata['beneficiary'] ?? $metadata['phone_number'] ?? ''),
            'amount' => Money::naira((int) $this->amount)->toMajorUnitsString(),
            'oldBalance' => Money::naira((int) ($metadata['old_balance'] ?? 0))->toMajorUnitsString(),
            'newBalance' => Money::naira((int) ($metadata['new_balance'] ?? 0))->toMajorUnitsString(),
            'status' => MobileTransactionStatus::forDisplay(TransactionStatus::from($this->status)),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];

        if ($type === TransactionType::Electricity) {
            $row['details'] = [
                'customerName' => $metadata['customer_name'] ?? null,
                'meterToken' => $metadata['meter_token'] ?? null,
                'meterUnits' => $metadata['meter_units'] ?? null,
                'pinSerial' => $metadata['pin_serial'] ?? null,
                'pinInstructions' => $metadata['pin_instructions'] ?? null,
            ];
        }

        return $row;
    }
}
