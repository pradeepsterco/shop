<?php

namespace App\Shared\ValueObjects;

/**
 * Immutable Money value object representing monetary amounts in integer paise (1 INR = 100 paise).
 * All internal arithmetic is strictly performed on integers.
 * Floats are used solely for formatted human display.
 */
final class Money
{
    private readonly int $paise;

    private function __construct(int $paise)
    {
        $this->paise = $paise;
    }

    public static function fromPaise(int $paise): self
    {
        return new self($paise);
    }

    public static function fromRupees(int|float $rupees): self
    {
        return new self((int) round(((float) $rupees) * 100, 0, PHP_ROUND_HALF_UP));
    }

    public static function zero(): self
    {
        return new self(0);
    }

    public function getPaise(): int
    {
        return $this->paise;
    }

    /**
     * Float representation in Rupees solely for display / output purposes.
     */
    public function toRupees(): float
    {
        return $this->paise / 100.0;
    }

    /**
     * Format as Indian Rupee string with Indian digit grouping (lakhs, crores),
     * e.g. "₹23,65,669.00", "₹1,00,000.00", "₹999.00", "−₹1,234.00".
     */
    public function format(): string
    {
        $isNegative = $this->paise < 0;
        $absPaise = abs($this->paise);

        $rupees = intdiv($absPaise, 100);
        $paiseRemainder = $absPaise % 100;

        $rupeesStr = (string) $rupees;
        $length = strlen($rupeesStr);

        if ($length <= 3) {
            $formattedRupees = $rupeesStr;
        } else {
            $lastThree = substr($rupeesStr, -3);
            $remaining = substr($rupeesStr, 0, -3);

            $remainingLength = strlen($remaining);
            $firstGroupLength = $remainingLength % 2 === 0 ? 2 : 1;

            $pairs = [substr($remaining, 0, $firstGroupLength)];

            for ($i = $firstGroupLength; $i < $remainingLength; $i += 2) {
                $pairs[] = substr($remaining, $i, 2);
            }

            $formattedRupees = implode(',', $pairs).','.$lastThree;
        }

        $formattedAmount = sprintf('%s.%02d', $formattedRupees, $paiseRemainder);

        return $isNegative
            ? "−₹{$formattedAmount}"
            : "₹{$formattedAmount}";
    }

    /**
     * Alias for format().
     */
    public function getFormatted(): string
    {
        return $this->format();
    }

    public function add(self ...$others): self
    {
        $total = $this->paise;
        foreach ($others as $other) {
            $total += $other->paise;
        }

        return new self($total);
    }

    public function subtract(self $other): self
    {
        return new self($this->paise - $other->paise);
    }

    public function multiply(int $multiplier): self
    {
        return new self($this->paise * $multiplier);
    }

    /**
     * Calculate percentage using basis points (1% = 100 bps, 100% = 10,000 bps).
     * Integer rounded half-up to the nearest paisa.
     */
    public function percentageBps(int $basisPoints): self
    {
        $paise = (int) round(($this->paise * $basisPoints) / 10000, 0, PHP_ROUND_HALF_UP);

        return new self($paise);
    }

    /**
     * Calculate percentage using a rate (e.g. 18.0 for 18%).
     * Rounded half-up to the nearest paisa.
     */
    public function percentage(float $rate): self
    {
        $paise = (int) round(($this->paise * $rate) / 100.0, 0, PHP_ROUND_HALF_UP);

        return new self($paise);
    }

    public function isPositive(): bool
    {
        return $this->paise > 0;
    }

    public function isZero(): bool
    {
        return $this->paise === 0;
    }

    public function isNegative(): bool
    {
        return $this->paise < 0;
    }

    public function equals(self $other): bool
    {
        return $this->paise === $other->paise;
    }

    public function greaterThan(self $other): bool
    {
        return $this->paise > $other->paise;
    }

    public function lessThan(self $other): bool
    {
        return $this->paise < $other->paise;
    }

    public function __toString(): string
    {
        return $this->format();
    }
}
