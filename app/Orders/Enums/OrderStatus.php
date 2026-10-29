<?php

namespace App\Orders\Enums;

enum OrderStatus: string
{
    case Placed = 'placed';
    case PartiallyCancelled = 'partially_cancelled';
    case Cancelled = 'cancelled';
    case Completed = 'completed';
    case PartiallyRefunded = 'partially_refunded';
    case Refunded = 'refunded';

    public function label(): string
    {
        return match ($this) {
            self::Placed => 'Placed',
            self::PartiallyCancelled => 'Partially Cancelled',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
            self::PartiallyRefunded => 'Partially Refunded',
            self::Refunded => 'Refunded',
        };
    }
}
