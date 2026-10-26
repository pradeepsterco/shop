<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Services\ProductBuyBoxService;
use Illuminate\Console\Command;

class RefreshBuyBoxCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'catalog:refresh-buy-box {--product= : Specific product ID to refresh}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Refresh buy box listings, prices, discounts and stock summary on products';

    /**
     * Execute the console command.
     */
    public function handle(ProductBuyBoxService $service): int
    {
        $productId = $this->option('product');

        if ($productId) {
            $product = Product::find($productId);
            if (! $product) {
                $this->error("Product #{$productId} not found.");

                return self::FAILURE;
            }

            $service->refresh($product);
            $product->refresh();

            $this->info("Refreshed buy box for Product #{$productId}:");
            $this->table(
                ['Field', 'Value'],
                [
                    ['Buy Box Listing ID', $product->buy_box_listing_id ?? 'None'],
                    ['Min Price (paise)', $product->min_price ?? 'None'],
                    ['Buy Box MRP (paise)', $product->buy_box_mrp ?? 'None'],
                    ['Discount %', $product->discount_percent ?? '0%'],
                    ['In Stock', $product->in_stock ? 'Yes' : 'No'],
                ]
            );

            return self::SUCCESS;
        }

        $this->info('Refreshing buy box for all products...');
        $count = $service->refreshAll();
        $this->info("Successfully refreshed buy box for {$count} product(s).");

        return self::SUCCESS;
    }
}
