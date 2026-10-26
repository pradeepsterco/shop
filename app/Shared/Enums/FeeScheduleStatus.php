<?php

namespace App\Shared\Enums;

enum FeeScheduleStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case Archived = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Archived => 'Archived',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Draft => 'bg-amber-100 text-amber-800 dark:bg-amber-950/60 dark:text-amber-300',
            self::Active => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/60 dark:text-emerald-300',
            self::Archived => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300',
        };
    }
}
