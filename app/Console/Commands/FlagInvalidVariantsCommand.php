<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Console\Command;

class FlagInvalidVariantsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'products:flag-invalid-variants {--dry-run : Run inspection without writing changes to the database}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Scan catalog products for variant issues, flag needs_update, deactivate unsellable listings, and cleanup orphan variants.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Executing in DRY-RUN mode. No database modifications will occur.');
        }

        $scannedProducts = 0;
        $flaggedProducts = 0;
        $clearedProducts = 0;
        $deactivatedListings = 0;
        $cleanedOrphans = 0;

        $products = Product::with(['variants.listings', 'vertical.attributes'])->get();

        foreach ($products as $product) {
            $scannedProducts++;
            $issues = $product->variantIssues();

            if (! empty($issues)) {
                $flaggedProducts++;
                $this->line("<comment>[Product #{$product->id} - {$product->title}]</comment> found ".count($issues).' issue(s):');
                foreach ($issues as $issue) {
                    $this->line("  - [{$issue['code']}] {$issue['message']}");
                }

                if (! $dryRun) {
                    $product->update([
                        'needs_update' => true,
                        'needs_update_reason' => $issues,
                    ]);
                    $deactivated = $product->deactivateUnsellableListings();
                    $deactivatedListings += $deactivated;
                } else {
                    // Count unsellable listings without mutating
                    foreach ($product->variants as $variant) {
                        foreach ($variant->listings as $listing) {
                            if (! $listing->trashed() && $listing->status->value === 'active') {
                                if ($listing->selling_price <= 0 || $listing->mrp <= 0 || $listing->selling_price > $listing->mrp || blank($listing->sku)) {
                                    $deactivatedListings++;
                                }
                            }
                        }
                    }
                }
            } else {
                if ($product->needs_update) {
                    $clearedProducts++;
                    $this->info("[Product #{$product->id}] All issues resolved. Clearing needs_update.");

                    if (! $dryRun) {
                        $product->update([
                            'needs_update' => false,
                            'needs_update_reason' => null,
                        ]);
                    }
                }
            }
        }

        // Orphan variant cleanup: variants with no listings from any seller and no history
        $orphanVariants = ProductVariant::doesntHave('listings')->get();
        foreach ($orphanVariants as $orphan) {
            if (! $orphan->hasHistory()) {
                $cleanedOrphans++;
                if (! $dryRun) {
                    $orphan->delete(); // Soft-delete
                }
            }
        }

        $this->newLine();
        $this->table(
            ['Metric', 'Count', 'Mode'],
            [
                ['Products Scanned', $scannedProducts, $dryRun ? 'DRY-RUN' : 'APPLIED'],
                ['Products Flagged (Needs Update)', $flaggedProducts, $dryRun ? 'DRY-RUN' : 'APPLIED'],
                ['Products Cleared', $clearedProducts, $dryRun ? 'DRY-RUN' : 'APPLIED'],
                ['Unsellable Listings Deactivated', $deactivatedListings, $dryRun ? 'DRY-RUN' : 'APPLIED'],
                ['Orphan Variants Soft-Deleted', $cleanedOrphans, $dryRun ? 'DRY-RUN' : 'APPLIED'],
            ]
        );

        return self::SUCCESS;
    }
}
