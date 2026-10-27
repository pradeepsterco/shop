<?php

namespace App\Coupons\Enums;

enum ScopeType: string
{
    case Category = 'category';
    case Brand = 'brand';
    case Seller = 'seller';
    case Product = 'product';

    public function label(): string
    {
        return match ($this) {
            self::Category => 'Category',
            self::Brand => 'Brand',
            self::Seller => 'Seller',
            self::Product => 'Product',
        };
    }
}
