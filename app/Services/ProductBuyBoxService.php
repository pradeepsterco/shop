<?php

namespace App\Services;

use App\Enums\ListingStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Search\Contracts\SearchIndexerInterface;
use Illuminate\Database\Eloquent\Builder;

class ProductBuyBoxService
{
    /**
     * Resolve the buy box listing from a candidate listings query.
     * Priority 1: Cheapest in-stock active listing from an approved seller.
     * Priority 2: Fallback to cheapest active listing (even if stock is 0).
     *
     * @param  Builder<Listing>  $query
     */
    public function resolveBuyBoxListing(Builder $query): ?Listing
    {
        $inStock = (clone $query)
            ->where('stock', '>', 0)
            ->orderBy('selling_price', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        if ($inStock instanceof Listing) {
            return $inStock;
        }

        $fallback = (clone $query)
            ->orderBy('selling_price', 'asc')
            ->orderBy('id', 'asc')
            ->first();

        return $fallback instanceof Listing ? $fallback : null;
    }

    /**
     * Resolve the buy box listing for a specific product.
     */
    public function resolveForProduct(Product|int $product): ?Listing
    {
        $productId = $product instanceof Product ? $product->id : $product;
        $productModel = $product instanceof Product ? $product : Product::find($productId);

        if (! $productModel || $productModel->qc_status !== ProductQcStatus::Approved) {
            return null;
        }

        $baseListingQuery = Listing::query()
            ->where('status', ListingStatus::Active)
            ->whereHas('variant', function (Builder $q) use ($productId) {
                $q->where('product_id', $productId)
                    ->where('qc_status', VariantQcStatus::Approved);
            })
            ->whereHas('sellerProfile', function (Builder $q) {
                $q->where('status', SellerStatus::Approved);
            });

        return $this->resolveBuyBoxListing($baseListingQuery);
    }

    /**
     * Resolve the buy box listing for a specific product variant.
     */
    public function resolveForVariant(ProductVariant|int $variant): ?Listing
    {
        $variantId = $variant instanceof ProductVariant ? $variant->id : $variant;
        $variantModel = $variant instanceof ProductVariant
            ? $variant
            : ProductVariant::with('product')->find($variantId);

        if (! $variantModel || $variantModel->qc_status !== VariantQcStatus::Approved) {
            return null;
        }

        $product = $variantModel->relationLoaded('product') ? $variantModel->product : $variantModel->product()->first();
        if (! $product || $product->qc_status !== ProductQcStatus::Approved) {
            return null;
        }

        $baseListingQuery = Listing::query()
            ->where('product_variant_id', $variantId)
            ->where('status', ListingStatus::Active)
            ->whereHas('sellerProfile', function (Builder $q) {
                $q->where('status', SellerStatus::Approved);
            });

        return $this->resolveBuyBoxListing($baseListingQuery);
    }

    /**
     * Refresh the buy box columns for a single product.
     */
    public function refresh(Product|int $product): void
    {
        $productId = $product instanceof Product ? $product->id : $product;
        $productModel = $product instanceof Product ? $product : Product::find($productId);

        if (! $productModel) {
            return;
        }

        $buyBoxListing = $this->resolveForProduct($productModel);

        if ($buyBoxListing) {
            $mrp = (int) $buyBoxListing->mrp;
            $sellingPrice = (int) $buyBoxListing->selling_price;
            $discountPercent = $mrp > $sellingPrice
                ? (int) round((($mrp - $sellingPrice) / $mrp) * 100)
                : 0;
            $inStock = (int) $buyBoxListing->stock > 0;

            $productModel->forceFill([
                'buy_box_listing_id' => $buyBoxListing->id,
                'min_price' => $sellingPrice,
                'buy_box_mrp' => $mrp,
                'discount_percent' => $discountPercent,
                'in_stock' => $inStock,
            ])->saveQuietly();
        } else {
            $productModel->forceFill([
                'buy_box_listing_id' => null,
                'min_price' => null,
                'buy_box_mrp' => null,
                'discount_percent' => 0,
                'in_stock' => false,
            ])->saveQuietly();
        }

        // Saved quietly above, so model events never see buy-box changes: reindex explicitly.
        app(SearchIndexerInterface::class)->queue($productModel->id);
    }

    /**
     * Refresh buy box summary for all products in chunks.
     *
     * @return int Number of products refreshed
     */
    public function refreshAll(): int
    {
        $count = 0;
        Product::query()->chunkById(100, function ($products) use (&$count) {
            foreach ($products as $product) {
                $this->refresh($product);
                $count++;
            }
        });

        return $count;
    }
}
