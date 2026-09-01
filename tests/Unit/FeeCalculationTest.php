<?php

namespace Tests\Unit;

use App\Domain\Transactions\Enums\TransactionType;
use App\Domain\Transactions\Services\TransactionService;
use Tests\TestCase;

final class FeeCalculationTest extends TestCase
{
    private TransactionService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new TransactionService();
    }

    public function test_flat_fee(): void
    {
        // Airtime: 0 bps + ₦5.00 flat (500 kobo).
        $this->assertSame(500, $this->service->calculateFee(TransactionType::Airtime, 100000));
    }

    public function test_basis_points_fee(): void
    {
        // Data: 100 bps = 1% of ₦10,000 (1000000 kobo) = ₦100.00 (10000 kobo).
        $this->assertSame(10000, $this->service->calculateFee(TransactionType::Data, 1000000));
    }

    public function test_combined_fee_uses_integer_math(): void
    {
        // Electricity: 50 bps of 100005 kobo + 100 kobo flat
        // intdiv(100005 * 50, 10000) = intdiv(5000250, 10000) = 500 -> +100 = 600.
        $this->assertSame(600, $this->service->calculateFee(TransactionType::Electricity, 100005));
    }

    public function test_zero_fee_type(): void
    {
        $this->assertSame(0, $this->service->calculateFee(TransactionType::WalletFunding, 100000));
    }
}
