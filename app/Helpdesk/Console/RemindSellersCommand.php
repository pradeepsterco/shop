<?php

namespace App\Helpdesk\Console;

use App\Helpdesk\Services\HelpdeskService;
use Illuminate\Console\Command;

class RemindSellersCommand extends Command
{
    protected $signature = 'helpdesk:remind-sellers';

    protected $description = 'Email sellers whose reply on a support ticket is due soon (one reminder per deadline)';

    public function handle(HelpdeskService $helpdesk): int
    {
        $this->info('Reminded '.$helpdesk->remindSellers().' seller(s).');

        return self::SUCCESS;
    }
}
