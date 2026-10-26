<?php

namespace App\Settlements\Enums;

enum PayoutStatus: string
{
    case Draft = 'draft';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Processing => 'Processing',
            self::Paid => 'Paid / Disbursed',
            self::Failed => 'Failed / Rejected',
        };
    }
}
