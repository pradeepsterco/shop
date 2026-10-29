<?php

namespace App\Shipping\Enums;

enum ShipmentPurpose: string
{
    /** First shipment of a seller order (including re-attempts after a cancelled shipment). */
    case Original = 'original';

    /** Undelivered items of a partial delivery sent again. */
    case Reship = 'reship';

    /** Reverse pickup of returned items from the customer. */
    case CustomerReturn = 'customer_return';

    /** Forward shipment of a replacement order. */
    case Replacement = 'replacement';

    /** A returned item that failed QC, sent back to the customer at the seller's cost. */
    case ShipBack = 'ship_back';

    public function direction(): ShipmentDirection
    {
        return $this === self::CustomerReturn ? ShipmentDirection::Reverse : ShipmentDirection::Forward;
    }

    public function label(): string
    {
        return match ($this) {
            self::Original => 'Original',
            self::Reship => 'Reship',
            self::CustomerReturn => 'Customer Return',
            self::Replacement => 'Replacement',
            self::ShipBack => 'Ship Back to Customer',
        };
    }
}
