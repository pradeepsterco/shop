<?php

namespace App\Helpdesk\Console;

use App\Helpdesk\Services\HelpdeskService;
use Illuminate\Console\Command;

class ResolveUnansweredTicketsCommand extends Command
{
    protected $signature = 'helpdesk:resolve-unanswered {--days= : Days without a reply (defaults to helpdesk.pending_auto_resolve_days)}';

    protected $description = 'Resolve support tickets that have waited on the customer or seller with no reply for a number of days';

    public function handle(HelpdeskService $helpdesk): int
    {
        $days = $this->option('days') !== null ? (int) $this->option('days') : null;

        $this->info('Resolved '.$helpdesk->resolveUnanswered($days).' unanswered ticket(s).');

        return self::SUCCESS;
    }
}
