<?php

namespace App\Coupons\Enums;

enum RedemptionStatus: string
{
    case Redeemed = 'redeemed';
    case PartiallyReleased = 'partially_released';
    case Released = 'released';

    public function label(): string
    {
        return match ($this) {
            self::Redeemed => 'Redeemed',
            self::PartiallyReleased => 'Partially Released',
            self::Released => 'Released',
        };
    }
}
