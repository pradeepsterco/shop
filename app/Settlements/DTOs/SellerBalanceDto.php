<?php

namespace App\Settlements\DTOs;

use App\Shared\ValueObjects\Money;

final readonly class SellerBalanceDto
{
    public function __construct(
        public int $onHoldPaise,
        public int $eligiblePaise,
        public int $inPayoutPaise,
        public int $lifetimePaidPaise,
        public int $carriedNegativePaise = 0,
    ) {}

    public function onHoldMoney(): Money
    {
        return Money::fromPaise($this->onHoldPaise);
    }

    public function eligibleMoney(): Money
    {
        return Money::fromPaise($this->eligiblePaise);
    }

    public function inPayoutMoney(): Money
    {
        return Money::fromPaise($this->inPayoutPaise);
    }

    public function lifetimePaidMoney(): Money
    {
        return Money::fromPaise($this->lifetimePaidPaise);
    }

    public function carriedNegativeMoney(): Money
    {
        return Money::fromPaise($this->carriedNegativePaise);
    }

    public function netPayablePaise(): int
    {
        return max(0, $this->eligiblePaise);
    }

    public function netPayableMoney(): Money
    {
        return Money::fromPaise($this->netPayablePaise());
    }
}
