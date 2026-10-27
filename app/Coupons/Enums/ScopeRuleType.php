<?php

namespace App\Coupons\Enums;

enum ScopeRuleType: string
{
    case Include = 'include';
    case Exclude = 'exclude';

    public function label(): string
    {
        return match ($this) {
            self::Include => 'Include',
            self::Exclude => 'Exclude',
        };
    }
}
