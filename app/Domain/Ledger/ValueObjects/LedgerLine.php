<?php

namespace App\Domain\Ledger\ValueObjects;

use App\Domain\Ledger\Enums\EntryDirection;

/**
 * A single double-entry ledger line.
 *
 * @readonly
 */
final class LedgerLine
{
    private function __construct(
        public readonly string $accountCode,
        public readonly EntryDirection $direction,
        public readonly int $amount,
    ) {
    }

    public static function debit(string $accountCode, int $amount): self
    {
        return new self($accountCode, EntryDirection::Debit, $amount);
    }

    public static function credit(string $accountCode, int $amount): self
    {
        return new self($accountCode, EntryDirection::Credit, $amount);
    }
}
