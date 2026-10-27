<?php

namespace App\Coupons\Enums;

enum CouponFundedBy: string
{
    case Platform = 'platform';
    case Seller = 'seller';

    public function label(): string
    {
        return match ($this) {
            self::Platform => 'Platform',
            self::Seller => 'Seller',
        };
    }
}
