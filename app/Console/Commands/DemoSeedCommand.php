<?php

namespace App\Console\Commands;

use Database\Seeders\MarketplaceDemoSeeder;
use Illuminate\Console\Command;

class DemoSeedCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'demo:seed';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Seed marketplace demo data including multiple sellers, products, customers, and simulated purchases';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (! app()->isLocal()) {
            $this->error('Error: [demo:seed] can only be executed in local development environment.');

            return self::FAILURE;
        }

        $this->info('Starting Marketplace Demo Seeding...');
        $this->line('Environment: local');

        /** @var MarketplaceDemoSeeder $seeder */
        $seeder = app(MarketplaceDemoSeeder::class);
        $seeder->run();

        $stats = $seeder->stats;

        $this->newLine();
        $this->info('===========================================================');
        $this->info('           MARKETPLACE DEMO SEEDING SUMMARY                ');
        $this->info('===========================================================');

        // Sellers summary
        $this->line('<fg=yellow;options=bold>Sellers:</>');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Total Sellers Seeded', $stats['sellers']['total']],
                ['Approved Sellers (Active Stores)', $stats['sellers']['approved']],
                ['Pending Application (Step 4)', $stats['sellers']['pending']],
                ['Suspended Account (Inactive Listings)', $stats['sellers']['suspended']],
            ]
        );

        // Catalog summary
        $this->line('<fg=yellow;options=bold>Catalog & Inventory:</>');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Products Created in this run', $stats['catalog']['products']],
                ['Total Products in Catalog', $stats['catalog']['total_products_in_db']],
                ['Total Active / Inactive Listings', $stats['catalog']['listings']],
                ['Multi-Seller Competing Variants', $stats['catalog']['multi_seller_variants']],
            ]
        );

        // Customers summary
        $this->line('<fg=yellow;options=bold>Customers & Addresses:</>');
        $this->table(
            ['Metric', 'Count'],
            [
                ['Demo Customers (customer1..20)', $stats['customers']['total']],
                ['Total Users in Database', $stats['customers']['total_in_db']],
            ]
        );

        // Orders summary
        $this->line('<fg=yellow;options=bold>Simulated Purchases & Orders:</>');
        if ($stats['orders']['simulation_skipped']) {
            $this->warn("Order simulation was SKIPPED: {$stats['orders']['existing_orders']} demo orders already exist in database (Idempotent run).");
        } else {
            $this->table(
                ['Metric', 'Count / Value'],
                [
                    ['Orders Created in this run', $stats['orders']['orders_created']],
                    ['Single-Seller Orders', $stats['orders']['single_seller_orders']],
                    ['Multi-Seller Orders (2-4 sellers)', $stats['orders']['multi_seller_orders']],
                    ['Lines Skipped (out-of-stock)', $stats['orders']['lines_skipped_no_stock']],
                    ['Orders Skipped (zero valid lines)', $stats['orders']['orders_skipped_no_stock']],
                ]
            );
        }

        // Status breakdown
        $this->line('<fg=yellow;options=bold>Seller Packages by Status:</>');
        $statusRows = [];
        foreach ($stats['orders']['status_breakdown'] as $status => $count) {
            $statusRows[] = [ucfirst($status), $count];
        }
        $this->table(['Package Status', 'Count'], $statusRows);

        // Financials
        $this->line('<fg=yellow;options=bold>Order Financial Metrics:</>');
        $this->table(
            ['Metric', 'Amount (INR)'],
            [
                ['Gross Order Value', '₹'.number_format($stats['orders']['gross_value'] / 100)],
                ['Cancelled Packages Value', '₹'.number_format($stats['orders']['cancelled_value'] / 100)],
                ['Net Order Value', '₹'.number_format($stats['orders']['net_value'] / 100)],
            ]
        );

        // Demo Logins
        $this->newLine();
        $this->info('===========================================================');
        $this->info('                  ACCOUNTS & TEST LOGINS                   ');
        $this->info('===========================================================');
        $this->line('• <fg=cyan>Seller Login:</> seller1@example.com (Password: password) — Store: Delhi Toy Kingdom');
        $this->line('• <fg=cyan>Customer Login (High Orders):</> customer1@example.com (Password: password) — Aarav Sharma');
        $this->line('• <fg=cyan>Customer Login (Zero Orders):</> customer18@example.com (Password: password) — Sneha Rao');
        $this->line('• <fg=cyan>Admin Orders Overview:</> http://localhost:8000/admin/orders');
        $this->line('• <fg=cyan>Seller Orders Tab:</> http://localhost:8000/admin/sellers/1?tab=orders');
        $this->line('• <fg=cyan>Customer Orders Tab:</> http://localhost:8000/admin/customers/1?tab=orders');
        $this->newLine();

        return self::SUCCESS;
    }
}
