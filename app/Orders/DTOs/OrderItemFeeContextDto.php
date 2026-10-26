<?php

namespace App\Orders\DTOs;

use DateTimeInterface;

final readonly class OrderItemFeeContextDto
{
    public function __construct(
        public int $orderItemId,
        public string $sellerOrderId,
        public string $orderId,
        public int $sellerProfileId,
        public int $unitPricePaise,
        public int $qty,
        public float $gstRate,
        public ?int $verticalId,
        public ?float $weightKg,
        public ?string $sellerPickupState,
        public ?string $sellerPickupPincode,
        public ?string $deliveryState,
        public ?string $deliveryPincode,
        public string $paymentMethod,
        public bool $sellerHasPan,
        public DateTimeInterface $placedAt,
    ) {}

    public function lineTotalPaise(): int
    {
        return $this->unitPricePaise * $this->qty;
    }

    public function weightGrams(): ?int
    {
        if ($this->weightKg === null) {
            return null;
        }

        return (int) round($this->weightKg * 1000);
    }
}
