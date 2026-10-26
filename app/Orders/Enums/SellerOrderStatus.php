<?php

namespace App\Orders\Enums;

enum SellerOrderStatus: string
{
    case Placed = 'placed';
    case Confirmed = 'confirmed';
    case Packed = 'packed';
    case Shipped = 'shipped';
    case Delivered = 'delivered';
    case ReturnedToOrigin = 'rto';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Placed => 'Placed',
            self::Confirmed => 'Confirmed',
            self::Packed => 'Packed',
            self::Shipped => 'Shipped',
            self::Delivered => 'Delivered',
            self::ReturnedToOrigin => 'Returned to Origin',
            self::Cancelled => 'Cancelled',
        };
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
