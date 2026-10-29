<?php

namespace App\Orders\DTOs;

final readonly class UnitRefundAmount
{
    public function __construct(
        public int $grossPaise,
        public int $couponSharePaise,
        public int $amountPaise,
    ) {}
}
