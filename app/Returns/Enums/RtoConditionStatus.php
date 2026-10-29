<?php

namespace App\Returns\Enums;

enum RtoConditionStatus: string
{
    case Intact = 'intact';
    case Damaged = 'damaged';
    case WrongItem = 'wrong_item';
    case Incomplete = 'incomplete';

    public function label(): string
    {
        return match ($this) {
            self::Intact => 'Intact & Good Condition',
            self::Damaged => 'Damaged in Transit',
            self::WrongItem => 'Wrong Item Returned',
            self::Incomplete => 'Incomplete / Missing Parts',
        };
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Intact => 'bg-emerald-50 text-emerald-700 ring-1 ring-inset ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20',
            self::Damaged => 'bg-rose-50 text-rose-700 ring-1 ring-inset ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-500/20',
            self::WrongItem, self::Incomplete => 'bg-amber-50 text-amber-700 ring-1 ring-inset ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/20',
        };
    }
}
