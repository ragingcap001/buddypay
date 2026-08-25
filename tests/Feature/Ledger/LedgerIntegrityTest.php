<?php

namespace Tests\Feature\Ledger;

use App\Domain\Ledger\Constants\SystemAccounts;
use App\Domain\Ledger\Enums\LedgerAccountType;
use App\Domain\Ledger\Services\LedgerService;
use App\Domain\Ledger\ValueObjects\LedgerLine;
use App\Exceptions\LedgerNotBalancedException;
use App\Models\LedgerEntry;
use App\Models\LedgerTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class LedgerIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private LedgerService $ledger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->ledger = app(LedgerService::class);
        $this->ledger->getOrCreateAccount(SystemAccounts::CASH_FLOAT, LedgerAccountType::Asset, 'Cash Float');
        $this->ledger->getOrCreateAccount(SystemAccounts::PROVIDER_PAYABLE, LedgerAccountType::Liability, 'Provider Payable');
    }

    public function test_balanced_transaction_is_posted(): void
    {
        $result = $this->ledger->post('test settlement', [
            LedgerLine::debit(SystemAccounts::CASH_FLOAT, 1000),
            LedgerLine::credit(SystemAccounts::PROVIDER_PAYABLE, 1000),
        ]);

        $this->assertSame(2, $result->entries()->count());

        $report = $this->ledger->integrityReport();
        $this->assertTrue($report['balanced']);
        $this->assertSame(1000, $report['total_debits']);
        $this->assertSame(1000, $report['total_credits']);
    }

    public function test_unbalanced_transaction_is_never_posted(): void
    {
        $this->expectException(LedgerNotBalancedException::class);

        $this->ledger->post('unbalanced', [
            LedgerLine::debit(SystemAccounts::CASH_FLOAT, 1000),
            LedgerLine::credit(SystemAccounts::PROVIDER_PAYABLE, 999),
        ]);
    }

    public function test_no_rows_written_for_rejected_unbalanced_transaction(): void
    {
        try {
            $this->ledger->post('unbalanced', [
                LedgerLine::debit(SystemAccounts::CASH_FLOAT, 5),
                LedgerLine::credit(SystemAccounts::PROVIDER_PAYABLE, 3),
            ]);
            $this->fail('Expected LedgerNotBalancedException');
        } catch (LedgerNotBalancedException) {
            // expected
        }

        $this->assertSame(0, LedgerTransaction::count());
        $this->assertSame(0, LedgerEntry::count());
    }

    public function test_single_line_ledger_transaction_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->ledger->post('single line', [
            LedgerLine::debit(SystemAccounts::CASH_FLOAT, 1000),
        ]);
    }

    public function test_zero_or_negative_amounts_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->ledger->post('zero amount', [
            LedgerLine::debit(SystemAccounts::CASH_FLOAT, 0),
            LedgerLine::credit(SystemAccounts::PROVIDER_PAYABLE, 0),
        ]);
    }

    public function test_unknown_account_is_rejected(): void
    {
        $this->expectException(\App\Exceptions\UnknownLedgerAccountException::class);

        $this->ledger->post('unknown account', [
            LedgerLine::debit('NO_SUCH_ACCOUNT', 100),
            LedgerLine::credit(SystemAccounts::PROVIDER_PAYABLE, 100),
        ]);
    }

    public function test_ledger_entries_are_immutable_on_postgresql(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Immutability trigger only exists on PostgreSQL.');
        }

        $this->ledger->post('immutable test', [
            LedgerLine::debit(SystemAccounts::CASH_FLOAT, 100),
            LedgerLine::credit(SystemAccounts::PROVIDER_PAYABLE, 100),
        ]);

        $entry = LedgerEntry::first();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('ledger_entries')->where('id', $entry->id)->update(['amount' => 1]);
    }
}
