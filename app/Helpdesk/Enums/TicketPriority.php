<?php

namespace App\Helpdesk\Enums;

enum TicketPriority: string
{
    case Low = 'low';
    case Normal = 'normal';
    case High = 'high';
    case Urgent = 'urgent';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function badgeClasses(): string
    {
        return match ($this) {
            self::Low => 'text-zinc-500 dark:text-zinc-400',
            self::Normal => 'text-zinc-700 dark:text-zinc-300',
            self::High => 'text-amber-700 dark:text-amber-400',
            self::Urgent => 'text-rose-700 dark:text-rose-400',
        };
    }
}
