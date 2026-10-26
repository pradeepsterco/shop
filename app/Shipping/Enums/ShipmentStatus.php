<?php

namespace App\Shipping\Enums;

enum ShipmentStatus: string
{
    case Created = 'created';
    case PickupScheduled = 'pickup_scheduled';
    case PickedUp = 'picked_up';
    case InTransit = 'in_transit';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case DeliveryFailed = 'delivery_failed';
    case RtoInitiated = 'rto_initiated';
    case RtoDelivered = 'rto_delivered';
    case Cancelled = 'cancelled';
    case Lost = 'lost';
    case Exception = 'exception';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::PickupScheduled => 'Pickup Scheduled',
            self::PickedUp => 'Picked Up',
            self::InTransit => 'In Transit',
            self::OutForDelivery => 'Out for Delivery',
            self::Delivered => 'Delivered',
            self::DeliveryFailed => 'Delivery Failed (NDR)',
            self::RtoInitiated => 'RTO Initiated',
            self::RtoDelivered => 'RTO Delivered',
            self::Cancelled => 'Cancelled',
            self::Lost => 'Lost',
            self::Exception => 'Exception',
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Delivered,
            self::RtoDelivered,
            self::Cancelled,
            self::Lost,
        ], true);
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Delivered => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20',
            self::RtoDelivered => 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-500/20',
            self::RtoInitiated, self::DeliveryFailed => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/20',
            self::Cancelled, self::Lost => 'bg-zinc-100 text-zinc-600 ring-1 ring-inset ring-zinc-500/20 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-700',
            self::Exception => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20',
            self::OutForDelivery, self::InTransit => 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20',
            self::PickedUp, self::PickupScheduled => 'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-600/20 dark:bg-indigo-500/10 dark:text-indigo-400 dark:ring-indigo-500/20',
            self::Created => 'bg-zinc-50 text-zinc-700 ring-1 ring-inset ring-zinc-600/20 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-600/30',
        };
    }
}
