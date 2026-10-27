<?php

namespace App\Content\Enums;

enum SectionVisibility: string
{
    case All = 'all';
    case Desktop = 'desktop';
    case Mobile = 'mobile';

    public function label(): string
    {
        return match ($this) {
            self::All => 'All Devices',
            self::Desktop => 'Desktop Only',
            self::Mobile => 'Mobile Only',
        };
    }
}
