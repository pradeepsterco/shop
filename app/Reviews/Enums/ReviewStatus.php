<?php

namespace App\Reviews\Enums;

enum ReviewStatus: string
{
    case Approved = 'approved';
    case Hidden = 'hidden';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Approved => 'Approved',
            self::Hidden => 'Hidden',
            self::Rejected => 'Rejected',
        };
    }
}
