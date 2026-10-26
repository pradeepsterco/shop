<?php

namespace App\Checkout\Services;

use App\Checkout\Models\CheckoutIntent;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Listing;
use App\Models\User;
use DomainException;

class CheckoutIntentService
{
    public const INTENT_TTL_MINUTES = 30;

    public const MAX_QTY_PER_LINE = 10;

    /**
     * Create a checkout intent from an array of cart lines.
     *
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function createFromCart(User $user, array $lines): CheckoutIntent
    {
        if (empty($lines)) {
            throw new DomainException('Your cart is empty.');
        }

        // Sellers cannot purchase their own listings
        if ($user->sellerProfile) {
            $sellerId = $user->sellerProfile->id;
            foreach ($lines as $line) {
                if ((int) ($line['seller_profile_id'] ?? 0) === $sellerId) {
                    throw new DomainException('You cannot purchase your own listings.');
                }
            }
        }

        return CheckoutIntent::create([
            'user_id' => $user->id,
            'source' => 'cart',
            'lines' => array_values($lines),
            'status' => 'pending',
            'expires_at' => now()->addMinutes(self::INTENT_TTL_MINUTES),
        ]);
    }

    /**
     * Create or reuse an idempotent Buy Now checkout intent for the user and listing.
     */
    public function createOrReuseBuyNowIntent(User $user, int $listingId, int $qty = 1): CheckoutIntent
    {
        $listing = Listing::with(['variant.product.images', 'sellerProfile'])->find($listingId);

        if (! $listing) {
            throw new DomainException('This item is not available.');
        }

        // Sellers cannot purchase their own listings
        if ($user->sellerProfile && $listing->seller_profile_id === $user->sellerProfile->id) {
            throw new DomainException('You cannot purchase your own listings.');
        }

        $availability = $this->checkListingAvailability($listing);
        if ($availability !== 'ok') {
            throw new DomainException($availability === 'out_of_stock' ? 'This item is out of stock.' : 'This item is not available.');
        }

        // Idempotency: look for an existing pending, unexpired Buy Now intent for this user and listing
        /** @var CheckoutIntent|null $existing */
        $existing = CheckoutIntent::query()
            ->where('user_id', $user->id)
            ->where('source', 'buy_now')
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->latest('created_at')
            ->get()
            ->first(function (CheckoutIntent $intent) use ($listingId) {
                $lines = $intent->lines ?? [];

                return isset($lines[0]['listing_id']) && (int) $lines[0]['listing_id'] === $listingId;
            });

        if ($existing) {
            return $existing;
        }

        $stock = (int) $listing->stock;
        $effectiveQty = max(1, min($qty, min(self::MAX_QTY_PER_LINE, $stock > 0 ? $stock : 1)));
        $line = $this->buildLineSnapshot($listing, $effectiveQty);

        return CheckoutIntent::create([
            'user_id' => $user->id,
            'source' => 'buy_now',
            'lines' => [$line],
            'status' => 'pending',
            'expires_at' => now()->addMinutes(self::INTENT_TTL_MINUTES),
        ]);
    }

    /**
     * Update quantity for a line in the checkout intent.
     */
    public function updateLineQty(CheckoutIntent $intent, int $listingId, int $qty): void
    {
        if ($intent->isExpired() || $intent->status !== 'pending') {
            throw new DomainException('This checkout session has expired.');
        }

        $lines = $intent->lines ?? [];
        $listing = Listing::find($listingId);
        $stock = $listing ? (int) $listing->stock : 0;
        $cappedQty = max(1, min($qty, min(self::MAX_QTY_PER_LINE, max(1, $stock))));

        $updated = false;
        foreach ($lines as &$line) {
            if ((int) ($line['listing_id'] ?? 0) === $listingId) {
                $line['qty'] = $cappedQty;
                $updated = true;
                break;
            }
        }
        unset($line);

        if ($updated) {
            $intent->lines = $lines;
            $intent->save();
        }
    }

    /**
     * Remove a line from the checkout intent.
     */
    public function removeLine(CheckoutIntent $intent, int $listingId): void
    {
        if ($intent->isExpired() || $intent->status !== 'pending') {
            throw new DomainException('This checkout session has expired.');
        }

        $lines = array_values(array_filter($intent->lines ?? [], function ($line) use ($listingId) {
            return (int) ($line['listing_id'] ?? 0) !== $listingId;
        }));

        $intent->lines = $lines;
        $intent->save();
    }

    /**
     * Evaluate live state of items in an intent (prices, stock, availability).
     *
     * @return array<string, mixed>
     */
    public function evaluateIntent(CheckoutIntent $intent): array
    {
        $lines = $intent->lines ?? [];
        $listingIds = array_filter(array_map(fn ($l) => (int) ($l['listing_id'] ?? 0), $lines));

        $liveListings = Listing::with(['variant.product.images', 'sellerProfile'])
            ->whereIn('id', $listingIds)
            ->get()
            ->keyBy('id');

        $activeLines = [];
        $unavailableLines = [];
        $priceChanges = [];

        $mrpTotal = 0;
        $sellingTotal = 0;
        $itemsCount = 0;

        foreach ($lines as $line) {
            $listingId = (int) ($line['listing_id'] ?? 0);
            /** @var Listing|null $listing */
            $listing = $liveListings->get($listingId);

            $status = $this->checkListingAvailability($listing);

            if ($status !== 'ok') {
                $unavailableLines[] = [
                    'listing_id' => $listingId,
                    'name' => $line['name'] ?? 'Item',
                    'image' => $line['image'] ?? null,
                    'seller_name' => $line['seller_name'] ?? null,
                    'reason' => $status === 'out_of_stock' ? 'Out of stock' : 'Currently unavailable',
                ];

                continue;
            }

            // Available line
            $livePrice = (int) $listing->selling_price;
            $liveMrp = (int) max($listing->mrp, $listing->selling_price);
            $stock = (int) $listing->stock;
            $snapshotPrice = (int) ($line['unit_price'] ?? $livePrice);

            $effectiveQty = max(1, min((int) ($line['qty'] ?? 1), min(self::MAX_QTY_PER_LINE, $stock)));

            if ($livePrice !== $snapshotPrice) {
                $priceChanges[] = [
                    'listing_id' => $listingId,
                    'name' => $line['name'] ?? 'Item',
                    'old_price' => $snapshotPrice,
                    'new_price' => $livePrice,
                ];
            }

            $lineSubtotal = $livePrice * $effectiveQty;
            $lineMrpTotal = $liveMrp * $effectiveQty;

            $activeLines[] = [
                'listing_id' => $listingId,
                'name' => $line['name'] ?? 'Item',
                'sku' => $line['sku'] ?? $listing->sku,
                'image' => $line['image'] ?? null,
                'seller_profile_id' => $listing->seller_profile_id,
                'seller_name' => $line['seller_name'] ?? $listing->sellerProfile->store_name,
                'unit_price' => $livePrice,
                'mrp' => $liveMrp,
                'qty' => $effectiveQty,
                'stock' => $stock,
                'max_qty' => min(self::MAX_QTY_PER_LINE, $stock),
                'subtotal' => $lineSubtotal,
                'mrp_subtotal' => $lineMrpTotal,
            ];

            $sellingTotal += $lineSubtotal;
            $mrpTotal += $lineMrpTotal;
            $itemsCount += $effectiveQty;
        }

        $discountTotal = max(0, $mrpTotal - $sellingTotal);
        $deliveryCharge = 0; // Free for now
        $payableTotal = $sellingTotal + $deliveryCharge;

        return [
            'is_expired' => $intent->isExpired(),
            'active_lines' => $activeLines,
            'unavailable_lines' => $unavailableLines,
            'price_changes' => $priceChanges,
            'summary' => [
                'items_count' => $itemsCount,
                'mrp_total' => $mrpTotal,
                'selling_total' => $sellingTotal,
                'discount_total' => $discountTotal,
                'delivery_charge' => $deliveryCharge,
                'payable_total' => $payableTotal,
                'savings' => $discountTotal,
            ],
        ];
    }

    /**
     * Build display snapshot for a listing.
     *
     * @return array<string, mixed>
     */
    public function buildLineSnapshot(Listing $listing, int $qty): array
    {
        $variant = $listing->relationLoaded('variant') ? $listing->variant : $listing->variant()->first();
        $product = $variant ? ($variant->relationLoaded('product') ? $variant->product : $variant->product()->first()) : null;
        $seller = $listing->relationLoaded('sellerProfile') ? $listing->sellerProfile : $listing->sellerProfile()->first();

        $labels = [];
        if ($variant && ! empty($variant->variant_attributes)) {
            foreach ($variant->variant_attributes as $code => $val) {
                $label = $variant->optionDisplayLabel($code);
                $labels[] = $label !== '' ? $label : (string) $val;
            }
        }
        $labelsStr = implode(', ', array_filter($labels));
        $productTitle = $product ? $product->title : 'Product';
        $lineName = $labelsStr !== '' ? "{$productTitle} - {$labelsStr}" : $productTitle;

        $primaryImg = null;
        if ($product) {
            if ($product->relationLoaded('images')) {
                $primaryImg = $product->images->first(fn ($img) => $img->is_primary && $img->status === ProductImageStatus::Live)
                    ?? $product->images->first(fn ($img) => $img->status === ProductImageStatus::Live)
                    ?? $product->images->first();
            } else {
                $primaryImg = $product->images()->where('status', ProductImageStatus::Live)->orderByDesc('is_primary')->first()
                    ?? $product->images()->first();
            }
        }
        $image = $primaryImg ? $primaryImg->url() : null;

        $sellingPrice = (int) $listing->selling_price;
        $mrp = (int) max($listing->mrp, $sellingPrice);

        return [
            'listing_id' => $listing->id,
            'qty' => $qty,
            'product_id' => $product ? $product->id : 0,
            'product_variant_id' => $variant ? $variant->id : null,
            'seller_profile_id' => $listing->seller_profile_id,
            'seller_name' => $seller?->store_name,
            'name' => $lineName,
            'sku' => $listing->sku,
            'image' => $image,
            'unit_price' => $sellingPrice,
            'mrp' => $mrp,
        ];
    }

    /**
     * Check listing full availability.
     */
    private function checkListingAvailability(?Listing $listing): string
    {
        if (! $listing || $listing->trashed()) {
            return 'unavailable';
        }

        if ($listing->status !== ListingStatus::Active) {
            return 'unavailable';
        }

        $variant = $listing->relationLoaded('variant') ? $listing->variant : $listing->variant()->first();
        if (! $variant || $variant->trashed() || $variant->qc_status !== VariantQcStatus::Approved) {
            return 'unavailable';
        }

        $product = $variant->relationLoaded('product') ? $variant->product : $variant->product()->first();
        if (! $product || $product->qc_status !== ProductQcStatus::Approved) {
            return 'unavailable';
        }

        $seller = $listing->relationLoaded('sellerProfile') ? $listing->sellerProfile : $listing->sellerProfile()->first();
        if (! $seller || $seller->status !== SellerStatus::Approved) {
            return 'unavailable';
        }

        if ((int) $listing->stock <= 0) {
            return 'out_of_stock';
        }

        return 'ok';
    }
}
