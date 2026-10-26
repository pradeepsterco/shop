<?php

namespace App\Console\Commands;

use App\Checkout\Models\CheckoutIntent;
use Illuminate\Console\Command;

class PruneCheckoutIntentsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'checkout:prune-intents {--days=7 : Prune checkout intents older than this many days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune checkout intents created older than the specified days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = CheckoutIntent::where('created_at', '<', $cutoff)->delete();

        $this->info("Pruned {$deleted} checkout intents older than {$days}+ days.");

        return Command::SUCCESS;
    }
}
