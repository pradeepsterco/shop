<?php

namespace App\Helpdesk\Console;

use App\Helpdesk\Services\HelpdeskService;
use Illuminate\Console\Command;

class CloseResolvedTicketsCommand extends Command
{
    protected $signature = 'helpdesk:close-resolved {--days= : Days without activity (defaults to helpdesk.auto_close_resolved_days)}';

    protected $description = 'Close resolved support tickets that have had no activity for a number of days';

    public function handle(HelpdeskService $helpdesk): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;
        $closed = $helpdesk->closeStaleResolved($days);

        $this->info("Closed {$closed} resolved ticket(s).");

        return self::SUCCESS;
    }
}
