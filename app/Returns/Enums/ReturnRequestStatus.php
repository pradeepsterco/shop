<?php

namespace App\Returns\Enums;

enum ReturnRequestStatus: string
{
    /** Waiting for admin review. */
    case Requested = 'requested';

    /** Admin asked the customer for more information. */
    case InfoRequested = 'info_requested';

    /** Approved; return pickup booked (or being retried). */
    case Approved = 'approved';

    /** The return pickup reached the seller; QC and refund/replacement follow. */
    case Received = 'received';

    /** Handled (e.g. a missing-item claim handed to the refund/reship queue, or completed after QC). */
    case Resolved = 'resolved';

    case Rejected = 'rejected';

    /** Ended without a return, e.g. the pickup failed and was cancelled. The customer may request again within the window. */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Requested => 'Requested',
            self::InfoRequested => 'More information needed',
            self::Approved => 'Approved — pickup scheduled',
            self::Received => 'Received by seller',
            self::Resolved => 'Resolved',
            self::Rejected => 'Rejected',
            self::Closed => 'Closed',
        };
    }

    /**
     * Open returns hold the package settlement, count against the quantity guard and block account deletion.
     */
    public function isActive(): bool
    {
        return in_array($this, [self::Requested, self::InfoRequested, self::Approved, self::Received], true);
    }

    /**
     * @return list<string>
     */
    public static function activeValues(): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->isActive())));
    }
}
