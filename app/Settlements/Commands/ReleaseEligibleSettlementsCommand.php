<?php

namespace App\Settlements\Commands;

use App\Settlements\Contracts\SettlementServiceInterface;
use Illuminate\Console\Command;

class ReleaseEligibleSettlementsCommand extends Command
{
    protected $signature = 'settlements:release';

    protected $description = 'Release matured on-hold settlements whose return window has passed into eligible status';

    public function handle(SettlementServiceInterface $settlementService): int
    {
        $this->info('Checking for matured settlements to release...');

        $count = $settlementService->releaseMaturedSettlements();

        $this->info("Successfully released {$count} settlement(s) into eligible status.");

        return Command::SUCCESS;
    }
}
