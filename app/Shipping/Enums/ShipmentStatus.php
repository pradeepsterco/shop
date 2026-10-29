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
    case PartialDelivered = 'partial_delivered';
    case PickupFailed = 'pickup_failed';

    /**
     * Status wording for the shipment's direction: on a return pickup "Delivered" means
     * the package reached the seller.
     */
    public function labelFor(ShipmentDirection $direction): string
    {
        if ($direction === ShipmentDirection::Reverse) {
            return match ($this) {
                self::PickedUp => 'Picked Up from Customer',
                self::Delivered => 'Returned to Seller',
                self::Cancelled => 'Return Pickup Cancelled',
                default => $this->label(),
            };
        }

        return $this->label();
    }

    /**
     * Next statuses for the shipment's direction (courier lifecycle), used to offer and enforce simulation steps.
     *
     * @return list<self>
     */
    public function nextStatusesFor(ShipmentDirection $direction): array
    {
        return $direction === ShipmentDirection::Reverse
            ? $this->nextReverseStatuses()
            : $this->nextForwardStatuses();
    }

    /**
     * Return pickup lifecycle: pickup (with failed attempts and re-attempts) -> transit -> received by seller.
     * After failed attempts the courier cancels the pickup. Terminal: Delivered, Cancelled, Lost.
     *
     * @return list<self>
     */
    public function nextReverseStatuses(): array
    {
        return match ($this) {
            self::Created => [self::PickupScheduled, self::PickedUp, self::PickupFailed],
            self::PickupScheduled => [self::PickedUp, self::PickupFailed],
            self::PickupFailed => [self::PickupScheduled, self::PickedUp, self::Cancelled],
            self::PickedUp => [self::InTransit, self::Lost],
            self::InTransit => [self::Delivered, self::Lost],
            default => [],
        };
    }

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
            self::PartialDelivered => 'Partially Delivered',
            self::PickupFailed => 'Pickup Failed',
        };
    }

    /**
     * Statuses a forward shipment can move to next from this one (courier lifecycle).
     * Used to offer and enforce fake-carrier simulation steps; terminal statuses have none.
     *
     * @return list<self>
     */
    public function nextForwardStatuses(): array
    {
        return match ($this) {
            self::Created, self::PickupScheduled => [self::PickedUp, self::Lost, self::Exception],
            self::PickedUp => [self::InTransit, self::Lost, self::Exception],
            self::InTransit => [self::OutForDelivery, self::RtoInitiated, self::Lost, self::Exception],
            self::OutForDelivery => [self::Delivered, self::PartialDelivered, self::DeliveryFailed, self::Lost, self::Exception],
            self::DeliveryFailed => [self::OutForDelivery, self::RtoInitiated, self::Lost],
            self::RtoInitiated => [self::RtoDelivered, self::Lost],
            self::Exception => [self::InTransit, self::RtoInitiated, self::Lost],
            self::Delivered, self::PartialDelivered, self::RtoDelivered, self::Cancelled, self::Lost => [],
            // Pickup Failed only happens on return pickups
            self::PickupFailed => [],
        };
    }

    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Delivered,
            self::RtoDelivered,
            self::Cancelled,
            self::Lost,
            self::PartialDelivered,
        ], true);
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Delivered => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20',
            self::RtoDelivered => 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-500/20',
            self::RtoInitiated, self::DeliveryFailed, self::PickupFailed => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/20',
            self::Cancelled, self::Lost => 'bg-zinc-100 text-zinc-600 ring-1 ring-inset ring-zinc-500/20 dark:bg-zinc-800 dark:text-zinc-400 dark:ring-zinc-700',
            self::Exception => 'bg-red-50 text-red-700 ring-1 ring-inset ring-red-600/20 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20',
            self::PartialDelivered => 'bg-purple-50 text-purple-700 ring-1 ring-inset ring-purple-600/20 dark:bg-purple-500/10 dark:text-purple-400 dark:ring-purple-500/20',
            self::OutForDelivery, self::InTransit => 'bg-blue-50 text-blue-700 ring-1 ring-inset ring-blue-600/20 dark:bg-blue-500/10 dark:text-blue-400 dark:ring-blue-500/20',
            self::PickedUp, self::PickupScheduled => 'bg-indigo-50 text-indigo-700 ring-1 ring-inset ring-indigo-600/20 dark:bg-indigo-500/10 dark:text-indigo-400 dark:ring-indigo-500/20',
            self::Created => 'bg-zinc-50 text-zinc-700 ring-1 ring-inset ring-zinc-600/20 dark:bg-zinc-800 dark:text-zinc-300 dark:ring-zinc-600/30',
        };
    }
}
