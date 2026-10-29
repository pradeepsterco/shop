<?php

namespace App\Shipping\Enums;

enum ShipmentDirection: string
{
    /** Seller to customer. */
    case Forward = 'forward';

    /** Customer to seller (return pickup). */
    case Reverse = 'reverse';

    public function label(): string
    {
        return match ($this) {
            self::Forward => 'Forward',
            self::Reverse => 'Return Pickup',
        };
    }
}
