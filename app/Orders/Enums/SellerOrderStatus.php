<?php

namespace App\Orders\Enums;

enum SellerOrderStatus: string
{
    case Placed = 'placed';
    case Confirmed = 'confirmed';
    case Packed = 'packed';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case PartiallyDelivered = 'partially_delivered';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';
    case ReturnedToOrigin = 'rto';
    case LostInTransit = 'lost';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Placed => 'Placed',
            self::Confirmed => 'Confirmed',
            self::Packed => 'Packed',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
            self::PartiallyDelivered => 'Partially Delivered',
            self::PartiallyRefunded => 'Partially Refunded',
            self::Refunded => 'Refunded',
            self::ReturnedToOrigin => 'Returned to Origin',
            self::LostInTransit => 'Lost in Transit',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Closed without reaching the customer and nothing to collect from them.
     */
    public function isClosedUndelivered(): bool
    {
        return in_array($this, [self::Cancelled, self::ReturnedToOrigin, self::LostInTransit], true);
    }

    /**
     * Some or all units were refunded after a return.
     */
    public function isRefunded(): bool
    {
        return in_array($this, [self::PartiallyRefunded, self::Refunded], true);
    }

    /**
     * Reached the customer, fully or in part; COD is collected for what was delivered.
     */
    public function isDeliveredToCustomer(): bool
    {
        return in_array($this, [self::Delivered, self::PartiallyDelivered], true);
    }

    public function canCancelByCustomer(): bool
    {
        return in_array($this, [self::Placed, self::Confirmed], true);
    }

    public function canCancelBySeller(): bool
    {
        return in_array($this, [self::Placed, self::Confirmed], true);
    }

    public function canConfirm(): bool
    {
        return $this === self::Placed;
    }

    public function canPack(): bool
    {
        return $this === self::Confirmed;
    }

    public function canShip(): bool
    {
        return $this === self::Packed;
    }

    public function canDeliver(): bool
    {
        return $this === self::Shipped;
    }
}
