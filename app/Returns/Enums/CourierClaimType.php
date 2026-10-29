<?php

namespace App\Returns\Enums;

enum CourierClaimType: string
{
    case DamagedRto = 'damaged_rto';
    case IncompleteRto = 'incomplete_rto';
    case LostShipment = 'lost_shipment';
    case MissingItem = 'missing_item';

    public function label(): string
    {
        return match ($this) {
            self::DamagedRto => 'Damaged RTO Package',
            self::IncompleteRto => 'Incomplete / Tampered RTO',
            self::LostShipment => 'Lost in Transit',
            self::MissingItem => 'Missing Item Discrepancy',
        };
    }
}
