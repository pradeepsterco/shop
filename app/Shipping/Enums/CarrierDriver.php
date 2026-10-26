<?php

namespace App\Shipping\Enums;

enum CarrierDriver: string
{
    case Ekart = 'ekart';
    case Shiprocket = 'shiprocket';
    case Manual = 'manual';
    case Fake = 'fake';

    public function label(): string
    {
        return match ($this) {
            self::Ekart => 'Ekart Logistics',
            self::Shiprocket => 'Shiprocket',
            self::Manual => 'Manual / Self-Ship',
            self::Fake => 'Fake Carrier (Testing/Dev)',
        };
    }
}
