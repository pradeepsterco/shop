<?php

namespace App\Services;

use App\Enums\ListingStatus;
use App\Enums\ProductQcStatus;
use App\Enums\VariantQcStatus;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VariantSyncService
{
    /**
     * Synchronize product variants and seller listings based on submitted rows.
     * Runs in a single database transaction with pessimistic locking.
     *
     * @param  array<int|string, array<string, mixed>>  $validRows
     * @return array{synced_count: int, removed_count: int, product: Product}
     */
    public function sync(Product $product, SellerProfile $seller, array $validRows): array
    {
        return DB::transaction(function () use ($product, $seller, $validRows) {
            /** @var Product $lockedProduct */
            $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            $syncedVariantIds = [];
            $syncedKeys = [];

            // Preload vertical attributes to populate snapshot labels
            $vertical = $lockedProduct->relationLoaded('vertical') ? $lockedProduct->vertical : $lockedProduct->vertical()->with('attributes')->first();
            $attrs = $vertical ? ($vertical->relationLoaded('attributes') ? $vertical->getRelation('attributes') : $vertical->attributes()->get()) : collect();

            // 1. Process selected rows: create or update variants and seller listings
            foreach ($validRows as $row) {
                $combo = $row['variant_attributes'] ?? [];
                $normKey = ! empty($combo) ? VariantKeyNormalizer::normalize($combo) : ($row['key'] ?? $row['variant_key'] ?? '');

                if ($normKey === '') {
                    continue;
                }

                // Build snapshot for this combination
                $snapshot = [];
                if (! empty($combo)) {
                    foreach ($combo as $code => $value) {
                        $label = (string) $value;
                        $attr = $attrs->firstWhere('code', $code);
                        if ($attr && is_array($attr->options)) {
                            foreach ($attr->options as $opt) {
                                if (($opt['value'] ?? '') === (string) $value && ! empty($opt['label'])) {
                                    $label = $opt['label'];
                                    break;
                                }
                            }
                        }
                        $snapshot[$code] = [
                            'code' => $code,
                            'value' => (string) $value,
                            'label' => $label,
                        ];
                    }
                }

                // Check for existing variant (including soft-deleted)
                /** @var ProductVariant|null $variant */
                $variant = ProductVariant::withTrashed()
                    ->where('product_id', $lockedProduct->id)
                    ->where('variant_key', $normKey)
                    ->first();

                if ($variant) {
                    if ($variant->trashed()) {
                        $variant->restore();
                    }

                    $updateData = [];
                    if (! empty($combo)) {
                        $updateData['variant_attributes'] = $combo;
                        $updateData['attribute_snapshot'] = $snapshot;
                    }
                    if (isset($row['barcode']) && $row['barcode'] !== '') {
                        $updateData['barcode'] = $row['barcode'];
                    }
                    if (isset($row['weight']) && $row['weight'] !== '') {
                        $updateData['weight'] = (float) $row['weight'];
                    }
                    if (! empty($updateData)) {
                        $variant->update($updateData);
                    }
                } else {
                    $variant = ProductVariant::create([
                        'product_id' => $lockedProduct->id,
                        'variant_key' => $normKey,
                        'variant_attributes' => $combo,
                        'attribute_snapshot' => $snapshot,
                        'barcode' => ! empty($row['barcode']) ? $row['barcode'] : null,
                        'weight' => ! empty($row['weight']) ? (float) $row['weight'] : null,
                        'qc_status' => VariantQcStatus::Draft,
                    ]);
                }

                $syncedVariantIds[] = $variant->id;
                $syncedKeys[] = $normKey;

                // Sync the seller's listing
                $mrpPaise = (int) round(((float) $row['mrp']) * 100);
                $sellingPricePaise = (int) round(((float) $row['selling_price']) * 100);

                $statusVal = $row['status'] ?? 'active';
                $listingStatus = $statusVal instanceof ListingStatus
                    ? $statusVal
                    : (ListingStatus::tryFrom($statusVal) ?? ListingStatus::Active);

                Listing::createOrRestore([
                    'seller_profile_id' => $seller->id,
                    'product_variant_id' => $variant->id,
                    'sku' => strtoupper(trim((string) $row['sku'])),
                    'mrp' => $mrpPaise,
                    'selling_price' => $sellingPricePaise,
                    'stock' => (int) $row['stock'],
                    'status' => $listingStatus,
                ]);
            }

            // 2. Handle variants in DB that are no longer selected
            $unselectedVariants = ProductVariant::where('product_id', $lockedProduct->id)
                ->whereNotIn('id', $syncedVariantIds)
                ->get();

            // Guard: If product is approved, check that removal will not remove the last active valid variant
            if ($lockedProduct->qc_status === ProductQcStatus::Approved && $unselectedVariants->isNotEmpty()) {
                $remainingActiveCount = count(array_filter($validRows, fn ($r) => ($r['status'] ?? 'active') === 'active' && ($r['selling_price'] ?? 0) > 0));
                if ($remainingActiveCount === 0) {
                    throw new DomainException('Cannot remove the last active valid variant from an approved live product.');
                }
            }

            $removedCount = 0;
            foreach ($unselectedVariants as $unselectedVariant) {
                $outcome = $this->handleVariantRemoval($lockedProduct, $seller, $unselectedVariant, Auth::user());
                if ($outcome['soft_deleted']) {
                    $removedCount++;
                }
            }

            // 3. Re-evaluate integrity and update needs_update flag
            $lockedProduct->flagNeedsUpdate();

            return [
                'synced_count' => count($syncedVariantIds),
                'removed_count' => $removedCount,
                'product' => $lockedProduct->fresh(['variants.listings']),
            ];
        });
    }

    /**
     * Remove or archive a specific variant for a seller.
     * Enforces rules:
     * - No history (no orders, cart/wishlist, no other seller listing, never QC approved): soft-delete variant & listing
     * - Other seller has listing: archive only this seller's listing; keep variant
     * - Has history or was QC approved: archive listing (inactive, hidden from storefront); keep variant
     * - Block removal of last valid active variant on approved product
     *
     * @return array{outcome: string, message: string, soft_deleted: bool}
     *
     * @throws DomainException
     */
    public function removeVariant(Product $product, SellerProfile $seller, int $variantId, ?Authenticatable $user = null): array
    {
        return DB::transaction(function () use ($product, $seller, $variantId, $user) {
            /** @var Product $lockedProduct */
            $lockedProduct = Product::where('id', $product->id)->lockForUpdate()->firstOrFail();

            /** @var ProductVariant $variant */
            $variant = ProductVariant::where('product_id', $lockedProduct->id)->findOrFail($variantId);

            // Block removal of the last valid active variant on an approved product
            if ($lockedProduct->qc_status === ProductQcStatus::Approved) {
                $otherValidActiveVariantsCount = $lockedProduct->variants()
                    ->where('id', '!=', $variant->id)
                    ->whereHas('listings', fn ($q) => $q->where('status', ListingStatus::Active)->where('selling_price', '>', 0))
                    ->count();

                if ($otherValidActiveVariantsCount === 0) {
                    throw new DomainException('Cannot remove the last active valid variant from an approved live product.');
                }
            }

            $result = $this->handleVariantRemoval($lockedProduct, $seller, $variant, $user);

            // Re-evaluate integrity
            $lockedProduct->flagNeedsUpdate();

            return $result;
        });
    }

    /**
     * Internal removal logic with logging and outcome resolution.
     *
     * @return array{outcome: string, message: string, soft_deleted: bool}
     */
    protected function handleVariantRemoval(Product $product, SellerProfile $seller, ProductVariant $variant, ?Authenticatable $user = null): array
    {
        $hasOtherSellerListing = $variant->listings()
            ->where('seller_profile_id', '!=', $seller->id)
            ->exists();

        $hasHistory = $variant->hasHistory();
        $wasQcApproved = ($variant->qc_status === VariantQcStatus::Approved);

        $sellerListing = $variant->listings()
            ->where('seller_profile_id', $seller->id)
            ->first();

        if ($hasOtherSellerListing) {
            // Case 1: Other seller has listing -> archive this seller's listing, keep variant
            if ($sellerListing) {
                $sellerListing->deactivate();
            }
            $outcome = 'other_seller_listing_archived';
            $message = 'Your listing offer for this variant has been deactivated. The variant remains available in the catalog from other sellers.';
            $softDeleted = false;
        } elseif ($hasHistory || $wasQcApproved) {
            // Case 2: Has order/cart history or was previously QC-approved -> archive listing, keep variant
            if ($sellerListing) {
                $sellerListing->deactivate();
            }
            $outcome = 'history_retained_listing_archived';
            $message = 'This variant has history or prior approval and cannot be deleted. Your listing offer has been deactivated and hidden from customers.';
            $softDeleted = false;
        } else {
            // Case 3: Truly no history (no orders, no other seller, never QC approved) -> soft-delete both
            if ($sellerListing) {
                $sellerListing->delete();
            }
            $variant->delete();
            $outcome = 'variant_soft_deleted';
            $message = 'Variant and your listing offer have been completely removed.';
            $softDeleted = true;
        }

        // Log every removal (user, product, variant, outcome)
        Log::info('Product variant removed', [
            'user_id' => $user ? $user->getAuthIdentifier() : Auth::id(),
            'seller_profile_id' => $seller->id,
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'variant_key' => $variant->variant_key,
            'outcome' => $outcome,
        ]);

        return [
            'outcome' => $outcome,
            'message' => $message,
            'soft_deleted' => $softDeleted,
        ];
    }
}
