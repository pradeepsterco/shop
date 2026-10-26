<?php

namespace App\Settlements\Commands;

use App\Settlements\Contracts\PayoutServiceInterface;
use App\Settlements\DTOs\PayoutBatchCreateInput;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class CreateWeeklyPayoutBatchCommand extends Command
{
    protected $signature = 'settlements:payout-batch {--force : Force generation regardless of configured payout day}';

    protected $description = 'Generate weekly payout batch for eligible settlements on the configured local payout day';

    public function handle(PayoutServiceInterface $payoutService): int
    {
        $timezone = (string) config('app.display_timezone', config('settlements.timezone', 'Asia/Kolkata'));
        $now = CarbonImmutable::now($timezone);
        $payoutDay = strtolower((string) config('settlements.payout_day', 'wednesday'));

        if (! $this->option('force') && strtolower($now->format('l')) !== $payoutDay) {
            $this->info("Today ({$now->format('l')}) is not the configured payout day ({$payoutDay}) in {$timezone}. Skipping.");

            return Command::SUCCESS;
        }

        $this->info("Creating weekly payout batch for {$now->toDateString()} ({$timezone})...");

        $result = $payoutService->createWeeklyPayoutBatch(new PayoutBatchCreateInput(
            scheduledPayoutDate: $now,
            notes: "Automated weekly payout batch ({$now->toDateString()})",
        ));

        if ($result->batch) {
            $this->info("Created payout batch #{$result->batch->batch_number} with {$result->payoutCount} payout(s).");
        } else {
            $this->warn('No eligible payouts found to create a batch.');
        }

        return Command::SUCCESS;
    }
}
