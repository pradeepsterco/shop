<?php

namespace App\Console\Commands;

use App\Models\Cart;
use Illuminate\Console\Command;

class PruneGuestCartsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cart:prune-guest-carts {--days=60 : Prune guest carts inactive for this many days}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune guest carts whose last_activity_at is older than the specified days';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = (int) $this->option('days');
        $cutoff = now()->subDays($days);

        $deleted = Cart::whereNull('user_id')
            ->where(function ($query) use ($cutoff) {
                $query->where('last_activity_at', '<', $cutoff)
                    ->orWhere(function ($fallback) use ($cutoff) {
                        $fallback->whereNull('last_activity_at')
                            ->where('created_at', '<', $cutoff);
                    });
            })
            ->delete();

        $this->info("Pruned {$deleted} guest carts inactive for {$days}+ days.");

        return Command::SUCCESS;
    }
}
