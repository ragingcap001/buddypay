<?php

namespace App\Domain\Ledger\Services;

use App\Domain\Ledger\Constants\SystemAccounts;
use App\Domain\Ledger\Enums\EntryDirection;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\ValueObjects\LedgerLine;
use App\Domain\Transactions\Support\ReferenceGenerator;
use App\Exceptions\LedgerNotBalancedException;
use App\Exceptions\UnknownLedgerAccountException;
use App\Models\LedgerAccount;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use InvalidArgumentException;

/**
 * Double-entry ledger posting service.
 *
 * Invariants enforced here:
 *  - A ledger transaction has at least two lines.
 *  - Every line has a positive integer amount (minor units).
 *  - SUM(DEBITS) === SUM(CREDITS) — unbalanced postings are never written.
 *  - All referenced accounts must exist.
 *  - Entries are append-only (enforced at the database level as well).
 *
 * All methods must be called inside the caller's database transaction so
 * that posting is atomic with the financial state it represents.
 */
final class LedgerService
{
    /**
     * Fetch or create a ledger account by its unique code.
     */
    public function getOrCreateAccount(string $code, LedgerAccountType $type, string $name): LedgerAccount
    {
        $account = LedgerAccount::where('code', $code)->first();

        if ($account !== null) {
            return $account;
        }

        return LedgerAccount::create([
            'code' => $code,
            'type' => $type->value,
            'name' => $name,
            'currency' => config('ase.base_currency', 'NGN'),
        ]);
    }

    /**
     * The customer wallet account for a user (a liability of the platform).
     */
    public function walletAccount(int $userId): LedgerAccount
    {
        return $this->getOrCreateAccount(
            SystemAccounts::walletAccountCode($userId),
            LedgerAccountType::Liability,
            "Customer Wallet (User {$userId})",
        );
    }

    /**
     * Post a balanced ledger transaction.
     *
     * @param  array<int, LedgerLine>  $lines
     */
    public function post(string $description, array $lines, ?string $reference = null): LedgerTransaction
    {
        if (count($lines) < 2) {
            throw new InvalidArgumentException('A ledger transaction requires at least two lines.');
        }

        $totalDebits = 0;
        $totalCredits = 0;

        foreach ($lines as $line) {
            if ($line->amount <= 0) {
                throw new InvalidArgumentException('Ledger line amounts must be positive integers in minor units.');
            }

            if ($line->direction === EntryDirection::Debit) {
                $totalDebits += $line->amount;
            } else {
                $totalCredits += $line->amount;
            }

            if (! LedgerAccount::where('code', $line->accountCode)->exists()) {
                throw new UnknownLedgerAccountException($line->accountCode);
            }
        }

        if ($totalDebits !== $totalCredits) {
            throw new LedgerNotBalancedException($totalDebits, $totalCredits);
        }

        $ledgerTransaction = LedgerTransaction::create([
            'reference' => $reference ?? ReferenceGenerator::ledger(),
            'description' => $description,
            'status' => 'POSTED',
        ]);

        foreach ($lines as $line) {
            $account = LedgerAccount::where('code', $line->accountCode)->firstOrFail();

            LedgerEntry::create([
                'ledger_transaction_id' => $ledgerTransaction->id,
                'ledger_account_id' => $account->id,
                'direction' => $line->direction->value,
                'amount' => $line->amount,
            ]);
        }

        return $ledgerTransaction;
    }

    /**
     * Assert that all posted ledger entries balance globally.
     * Used by tests and reconciliation tooling.
     *
     * @return array{total_debits: int, total_credits: int, balanced: bool}
     */
    public function integrityReport(): array
    {
        $row = LedgerEntry::selectRaw(
                "COALESCE(SUM(CASE WHEN direction = 'DEBIT' THEN amount ELSE 0 END), 0) as total_debits, ".
                "COALESCE(SUM(CASE WHEN direction = 'CREDIT' THEN amount ELSE 0 END), 0) as total_credits"
            )
            ->first();

        $totalDebits = (int) $row->total_debits;
        $totalCredits = (int) $row->total_credits;

        return [
            'total_debits' => $totalDebits,
            'total_credits' => $totalCredits,
            'balanced' => $totalDebits === $totalCredits,
        ];
    }
}
