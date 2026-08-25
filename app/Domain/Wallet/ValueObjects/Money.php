<?php

namespace App\Domain\Wallet\ValueObjects;

/**
 * Immutable money value object.
 *
 * Amounts are stored as INTEGER minor units only (kobo for NGN).
 * Floating point arithmetic must never be used for money.
 *
 *     ₦1,000.50 === Money::naira(100050)
 */
final class Money
{
    private function __construct(
        public readonly int $amount,
        public readonly string $currency,
    ) {
    }

    /**
     * Create money from integer minor units (kobo for NGN).
     */
    public static function fromMinorUnits(int $amount, string $currency = 'NGN'): self
    {
        return new self($amount, strtoupper($currency));
    }

    /**
     * Convenience constructor for NGN amounts in kobo.
     */
    public static function naira(int $kobo): self
    {
        return new self($kobo, 'NGN');
    }

    public function add(self $other): self
    {
        $this->assertSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(self $other): self
    {
        $this->assertSameCurrency($other);

        $result = new self($this->amount - $other->amount, $this->currency);

        if ($result->amount < 0) {
            throw new \InvalidArgumentException(
                "Money subtraction cannot produce a negative amount ({$this->amount} - {$other->amount})."
            );
        }

        return $result;
    }

    /**
     * Multiply by a non-negative integer (e.g. quantity).
     * Fractional multipliers are not allowed for money.
     */
    public function multiply(int $factor): self
    {
        if ($factor < 0) {
            throw new \InvalidArgumentException('Money can only be multiplied by a non-negative integer.');
        }

        return new self($this->amount * $factor, $this->currency);
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public function isPositive(): bool
    {
        return $this->amount > 0;
    }

    public function isGreaterThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function isLessThan(self $other): bool
    {
        $this->assertSameCurrency($other);

        return $this->amount < $other->amount;
    }

    public function equals(self $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    /**
     * Format as a major-unit decimal string, e.g. 100050 => "1000.50".
     * Used for display only — never for calculation.
     */
    public function toMajorUnitsString(int $minorUnitsPerUnit = 100): string
    {
        $sign = $this->amount < 0 ? '-' : '';
        $abs = abs($this->amount);
        $major = intdiv($abs, $minorUnitsPerUnit);
        $minor = $abs % $minorUnitsPerUnit;
        $pad = max(strlen((string) intdiv($minorUnitsPerUnit, 10)), 1);

        return $sign.number_format($major, 0, '.', ',').'.'.str_pad((string) $minor, $pad, '0', STR_PAD_LEFT);
    }

    public function toMinorUnits(): int
    {
        return $this->amount;
    }

    private function assertSameCurrency(self $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new \InvalidArgumentException(
                "Cannot combine money of different currencies ({$this->currency} vs {$other->currency})."
            );
        }
    }
}
