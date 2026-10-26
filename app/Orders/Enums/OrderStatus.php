<?php

namespace App\Orders\Enums;

enum OrderStatus: string
{
    case Placed = 'placed';
    case PartiallyCancelled = 'partially_cancelled';
    case Cancelled = 'cancelled';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Placed => 'Placed',
            self::PartiallyCancelled => 'Partially Cancelled',
            self::Cancelled => 'Cancelled',
            self::Completed => 'Completed',
        };
    }
}
