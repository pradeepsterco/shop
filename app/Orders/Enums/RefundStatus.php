<?php

namespace App\Orders\Enums;

enum RefundStatus: string
{
    /** Nothing to pay back: the customer was never charged for these units (e.g. COD not collected). */
    case NotCollected = 'not_collected';

    /** Customer was charged; awaiting manual payout by admin. */
    case Pending = 'pending';

    /** Paid out by admin; UTR recorded. */
    case Paid = 'paid';

    /** Payout failed (reason recorded); returns to pending when the customer updates payout details. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::NotCollected => 'Not Charged',
            self::Pending => 'Refund Pending',
            self::Paid => 'Refunded',
            self::Failed => 'Refund Failed',
        };
    }

    /**
     * Still owed to the customer (blocks account deletion).
     */
    public function isOpen(): bool
    {
        return $this === self::Pending || $this === self::Failed;
    }
}
