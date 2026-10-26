<?php

namespace App\Settlements\Enums;

enum SettlementStatus: string
{
    case Pending = 'pending';
    case OnHold = 'on_hold';
    case Eligible = 'eligible';
    case InPayout = 'in_payout';
    case Paid = 'paid';
    case Void = 'void';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::OnHold => 'On Hold',
            self::Eligible => 'Eligible for Payout',
            self::InPayout => 'In Payout',
            self::Paid => 'Paid',
            self::Void => 'Void / Cancelled',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Pending => 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300 border-amber-200 dark:border-amber-800',
            self::OnHold => 'bg-blue-50 text-blue-700 dark:bg-blue-950/40 dark:text-blue-300 border-blue-200 dark:border-blue-800',
            self::Eligible => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800',
            self::InPayout => 'bg-purple-50 text-purple-700 dark:bg-purple-950/40 dark:text-purple-300 border-purple-200 dark:border-purple-800',
            self::Paid => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 border-zinc-200 dark:border-zinc-700',
            self::Void => 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300 border-red-200 dark:border-red-800',
        };
    }
}
