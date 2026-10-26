<?php

namespace App\Settlements\DTOs;

use App\Settlements\Enums\SettlementStatus;
use App\Shared\ValueObjects\Money;
use Carbon\CarbonImmutable;

final readonly class SettlementSummaryDto
{
    public function __construct(
        public string $settlementId,
        public string $sellerOrderId,
        public int $sellerId,
        public SettlementStatus $status,
        public int $grossPaise,
        public int $commissionPaise,
        public int $closingFeePaise,
        public int $shippingFeePaise,
        public int $paymentFeePaise,
        public int $gstOnFeesPaise,
        public int $tdsPaise,
        public int $tcsPaise,
        public int $netPaise,
        public int $returnWindowDays,
        public ?CarbonImmutable $holdUntil,
        public ?CarbonImmutable $releasedAt,
        public ?string $payoutId = null,
        public ?CarbonImmutable $createdAt = null,
    ) {}

    public function grossMoney(): Money
    {
        return Money::fromPaise($this->grossPaise);
    }

    public function deductionsPaise(): int
    {
        return $this->commissionPaise
            + $this->closingFeePaise
            + $this->shippingFeePaise
            + $this->paymentFeePaise
            + $this->gstOnFeesPaise
            + $this->tdsPaise
            + $this->tcsPaise;
    }

    public function deductionsMoney(): Money
    {
        return Money::fromPaise($this->deductionsPaise());
    }

    public function netMoney(): Money
    {
        return Money::fromPaise($this->netPaise);
    }
}
