<?php

namespace App\Domain\Transactions\Support;

use App\Domain\Transactions\Enums\TransactionType;

/**
 * Maps between the internal TransactionType enum (uppercase, CABLE_TV)
 * and the mobile contract's lowercase vocabulary (cable), which the
 * contract also uses as the `type` query filter on
 * GET /v1/user/transactions.
 */
final class MobileTransactionType
{
    public static function forDisplay(TransactionType $type): string
    {
        return match ($type) {
            TransactionType::CableTv => 'cable',
            default => strtolower($type->value),
        };
    }

    public static function fromQueryValue(string $value): ?TransactionType
    {
        return match (strtolower($value)) {
            'airtime' => TransactionType::Airtime,
            'data' => TransactionType::Data,
            'electricity' => TransactionType::Electricity,
            'betting' => TransactionType::Betting,
            'cable' => TransactionType::CableTv,
            default => null,
        };
    }
}
