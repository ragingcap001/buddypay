<?php

namespace Tests\Unit;

use App\Domain\Wallet\ValueObjects\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_represents_amounts_as_integer_minor_units(): void
    {
        $money = Money::naira(100050);

        $this->assertSame(100050, $money->amount);
        $this->assertSame('NGN', $money->currency);
        $this->assertSame('1,000.50', $money->toMajorUnitsString());
    }

    public function test_addition(): void
    {
        $total = Money::naira(100)->add(Money::naira(250));

        $this->assertSame(350, $total->amount);
    }

    public function test_subtraction(): void
    {
        $remainder = Money::naira(1000)->subtract(Money::naira(400));

        $this->assertSame(600, $remainder->amount);
    }

    public function test_subtraction_rejects_negative_results(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::naira(100)->subtract(Money::naira(200));
    }

    public function test_multiplication_uses_integer_factors_only(): void
    {
        $total = Money::naira(1500)->multiply(3);

        $this->assertSame(4500, $total->amount);
    }

    public function test_multiplication_rejects_negative_factors(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::naira(1500)->multiply(-1);
    }

    public function test_currency_mismatches_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Money::naira(100)->add(Money::fromMinorUnits(100, 'USD'));
    }

    public function test_comparisons(): void
    {
        $this->assertTrue(Money::naira(200)->isGreaterThan(Money::naira(199)));
        $this->assertTrue(Money::naira(1).isLessThan(Money::naira(2)));
        $this->assertTrue(Money::naira(5)->equals(Money::naira(5)));
        $this->assertTrue(Money::naira(0)->isZero());
        $this->assertTrue(Money::naira(1)->isPositive());
    }

    public function test_zero_kobo_formats_correctly(): void
    {
        $this->assertSame('0.00', Money::naira(0)->toMajorUnitsString());
        $this->assertSame('10.05', Money::naira(1005)->toMajorUnitsString());
    }
}
