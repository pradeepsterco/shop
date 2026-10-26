<?php

namespace App\Shared\Enums;

enum ShippingZone: string
{
    case Local = 'local';
    case Regional = 'regional';
    case National = 'national';

    public function label(): string
    {
        return match ($this) {
            self::Local => 'Local (Same City / First 3 Pincode Digits)',
            self::Regional => 'Regional (Same State)',
            self::National => 'National (Rest of India)',
        };
    }
}
