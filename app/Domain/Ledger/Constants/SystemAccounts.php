<?php

namespace App\Domain\Ledger\Constants;

use App\Domain\Ledger\Enums\LedgerAccountType;

/**
 * System-wide ledger account codes.
 *
 * Customer wallet accounts are created per user with code "WALLET:{user_id}"
 * and are LIABILITY accounts (the platform owes the customer their balance).
 */
final class SystemAccounts
{
    /** Internal cash float. */
    public const CASH_FLOAT = 'CASH_FLOAT';

    /** Amounts receivable from funding/payment providers. */
    public const FUNDING_RECEIVABLE = 'FUNDING_RECEIVABLE';

    /** Amounts payable to bill/payment providers. */
    public const PROVIDER_PAYABLE = 'PROVIDER_PAYABLE';

    /** Transaction fee revenue. */
    public const REVENUE_TRANSACTION_FEE = 'REVENUE_TRANSACTION_FEE';

    /** Unclassified movements awaiting reconciliation. */
    public const SUSPENSE = 'SUSPENSE';

    /**
     * @return array<string, array{type: LedgerAccountType, name: string}>
     */
    public static function all(): array
    {
        return [
            self::CASH_FLOAT => ['type' => LedgerAccountType::Asset, 'name' => 'Cash Float'],
            self::FUNDING_RECEIVABLE => ['type' => LedgerAccountType::Asset, 'name' => 'Funding Receivable'],
            self::PROVIDER_PAYABLE => ['type' => LedgerAccountType::Liability, 'name' => 'Provider Payable'],
            self::REVENUE_TRANSACTION_FEE => ['type' => LedgerAccountType::Revenue, 'name' => 'Transaction Fee Revenue'],
            self::SUSPENSE => ['type' => LedgerAccountType::Liability, 'name' => 'Suspense'],
        ];
    }

    public static function walletAccountCode(int $userId): string
    {
        return 'WALLET:'.$userId;
    }
}
