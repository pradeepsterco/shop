<?php

namespace App\Shipping\DTOs;

final readonly class CarrierRateEstimateDto
{
    public function __construct(
        public int $totalChargesPaise,
        public int $freightPaise = 0,
        public int $codChargePaise = 0,
        public ?int $tatDays = null,
    ) {}
}
