<?php

namespace App\Returns\Enums;

enum CourierClaimStatus: string
{
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Settled = 'settled';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted to Courier',
            self::Approved => 'Approved by Courier',
            self::Rejected => 'Claim Rejected',
            self::Settled => 'Settled & Reimbursed',
        };
    }

    /**
     * Allowed next statuses: the courier approves or rejects a submitted claim, and an approved claim is settled.
     *
     * @return list<self>
     */
    public function nextStatuses(): array
    {
        return match ($this) {
            self::Submitted => [self::Approved, self::Rejected],
            self::Approved => [self::Settled],
            self::Rejected, self::Settled => [],
        };
    }
}
