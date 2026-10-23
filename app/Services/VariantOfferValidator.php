<?php

namespace App\Services;

use App\Models\Listing;
use App\Models\Product;
use App\Models\VerticalAttribute;

class VariantOfferValidator
{
    /**
     * Determines if a row is completely untouched/empty.
     *
     * @param  array<string, mixed>  $row
     */
    public static function isRowBlank(array $row): bool
    {
        $sku = trim((string) ($row['sku'] ?? ''));
        $mrp = trim((string) ($row['mrp'] ?? ''));
        $sellingPrice = trim((string) ($row['selling_price'] ?? ''));
        $barcode = trim((string) ($row['barcode'] ?? ''));
        $weight = trim((string) ($row['weight'] ?? ''));

        return $sku === '' && $mrp === '' && $sellingPrice === '' && $barcode === '' && $weight === '';
    }

    /**
     * Validates a collection of variant rows for a seller.
     *
     * @param  array<int|string, array<string, mixed>>  $rows
     * @return array{
     *     validRows: array<int|string, array<string, mixed>>,
     *     errors: array<string, string>,
     *     ignoredCount: int
     * }
     */
    public function validateRows(
        array $rows,
        int $sellerProfileId,
        ?Product $product = null,
        bool $requireAtLeastOneActive = true
    ): array {
        $validRows = [];
        $errors = [];
        $ignoredCount = 0;
        $seenSkus = [];
        $activeValidCount = 0;

        // Preload vertical attributes if product is present
        $attributesMap = [];
        if ($product) {
            $vertical = $product->relationLoaded('vertical')
                ? $product->vertical
                : $product->vertical()->with('attributes')->first();

            if ($vertical) {
                $attrs = $vertical->relationLoaded('attributes')
                    ? $vertical->getRelation('attributes')
                    : $vertical->attributes()->get();

                foreach ($attrs as $attr) {
                    $attributesMap[$attr->code] = $attr;
                }
            }
        }

        // Get existing variant keys, IDs, and option values on this product (to allow existing variants using newly hidden/retired options and prevent false-positive SKU collisions)
        $existingVariantKeys = [];
        $existingProductOptionValues = [];
        $productVariantIds = [];
        $productVariantIdsByKey = [];
        if ($product) {
            $productVariants = $product->variants()->withTrashed()->get(['id', 'variant_key', 'variant_attributes', 'deleted_at']);
            $productVariantIds = $productVariants->pluck('id')->all();
            $existingVariantKeys = $productVariants->pluck('variant_key')->all();
            foreach ($productVariants as $ev) {
                if ($ev->variant_key) {
                    $productVariantIdsByKey[$ev->variant_key] = $ev->id;
                }
                if (! $ev->trashed()) {
                    foreach ($ev->variant_attributes as $c => $v) {
                        $existingProductOptionValues[$c][] = (string) $v;
                    }
                }
            }
        }

        foreach ($rows as $key => $row) {
            // 1. Check if completely untouched blank row
            if (static::isRowBlank($row)) {
                $ignoredCount++;

                continue;
            }

            $rowKey = (string) ($row['key'] ?? $row['variant_key'] ?? $key);
            $variantId = isset($row['variant_id']) && $row['variant_id']
                ? (int) $row['variant_id']
                : (isset($row['id']) && $row['id'] ? (int) $row['id'] : ($productVariantIdsByKey[$rowKey] ?? null));
            $rowErrors = [];

            // 2. Validate SKU
            $sku = trim((string) ($row['sku'] ?? ''));
            if ($sku === '') {
                $rowErrors['sku'] = "Seller SKU is required for variant [{$rowKey}].";
            } else {
                $upperSku = strtoupper($sku);
                if (isset($seenSkus[$upperSku])) {
                    $rowErrors['sku'] = "SKU '{$sku}' is duplicated in this submission.";
                } else {
                    $seenSkus[$upperSku] = $key;

                    // Check DB uniqueness per seller (ignoring this product's / this variant's listing if exists)
                    $query = Listing::where('seller_profile_id', $sellerProfileId)
                        ->where('sku', $upperSku);

                    if (! empty($productVariantIds)) {
                        $query->whereNotIn('product_variant_id', $productVariantIds);
                    } elseif ($variantId) {
                        $query->where('product_variant_id', '!=', $variantId);
                    }

                    $existsInDb = $query->exists();

                    if ($existsInDb) {
                        $rowErrors['sku'] = "SKU '{$sku}' is already in use by another listing in your inventory.";
                    }
                }
            }

            // 3. Validate MRP
            $mrpRaw = $row['mrp'] ?? null;
            $mrp = null;
            if ($mrpRaw === null || trim((string) $mrpRaw) === '') {
                $rowErrors['mrp'] = "MRP is required for variant [{$rowKey}].";
            } elseif (! is_numeric($mrpRaw) || (float) $mrpRaw <= 0) {
                $rowErrors['mrp'] = "MRP for [{$rowKey}] must be a positive number greater than 0.";
            } else {
                $mrp = (float) $mrpRaw;
            }

            // 4. Validate Selling Price
            $priceRaw = $row['selling_price'] ?? null;
            $sellingPrice = null;
            if ($priceRaw === null || trim((string) $priceRaw) === '') {
                $rowErrors['selling_price'] = "Selling price is required for variant [{$rowKey}].";
            } elseif (! is_numeric($priceRaw) || (float) $priceRaw <= 0) {
                $rowErrors['selling_price'] = "Selling price for [{$rowKey}] must be greater than 0.";
            } else {
                $sellingPrice = (float) $priceRaw;
            }

            // Price <= MRP check
            if ($mrp !== null && $sellingPrice !== null && $sellingPrice > $mrp) {
                $rowErrors['selling_price'] = "Selling price (₹{$sellingPrice}) cannot exceed MRP (₹{$mrp}) for [{$rowKey}].";
            }

            // 5. Validate Stock
            $stockRaw = $row['stock'] ?? null;
            if ($stockRaw === null || trim((string) $stockRaw) === '') {
                $rowErrors['stock'] = "Stock is required for variant [{$rowKey}].";
            } elseif (! is_numeric($stockRaw) || (int) $stockRaw < 0 || (string) ((int) $stockRaw) !== trim((string) $stockRaw)) {
                $rowErrors['stock'] = "Stock for [{$rowKey}] must be an integer 0 or greater.";
            }

            // 6. Validate Options
            // Sellers can keep options already present on this product's variants, but cannot add hidden/retired options to a new variant
            $isExistingVariant = in_array($rowKey, $existingVariantKeys, true);
            if (! $isExistingVariant && isset($row['variant_attributes']) && is_array($row['variant_attributes'])) {
                foreach ($row['variant_attributes'] as $attrCode => $optVal) {
                    if (isset($attributesMap[$attrCode])) {
                        /** @var VerticalAttribute $attr */
                        $attr = $attributesMap[$attrCode];
                        $options = is_array($attr->options) ? $attr->options : [];
                        $matched = null;
                        foreach ($options as $opt) {
                            if ($opt['value'] === (string) $optVal) {
                                $matched = $opt;
                                break;
                            }
                        }

                        $isAllowedExistingOption = in_array((string) $optVal, $existingProductOptionValues[$attrCode] ?? [], true);

                        if (! $matched && ! $isAllowedExistingOption) {
                            $rowErrors['options'] = "Option '{$optVal}' does not exist for attribute '{$attr->name}'.";
                        } elseif ($matched && ! $isAllowedExistingOption) {
                            if (! empty($matched['is_retired'])) {
                                $rowErrors['options'] = "Option '{$optVal}' is retired and cannot be added to a new variant.";
                            } elseif (! $matched['is_active']) {
                                $rowErrors['options'] = "Option '{$optVal}' is hidden and cannot be added to a new variant.";
                            }
                        }
                    }
                }
            }

            // 7. Status validity
            $status = $row['status'] ?? 'active';
            if ($status === 'active' && ! empty($rowErrors)) {
                // Cannot be active if any required fields are invalid
                $rowErrors['status'] = "Variant [{$rowKey}] cannot be active while it has validation errors.";
            }

            if (! empty($rowErrors)) {
                foreach ($rowErrors as $field => $msg) {
                    $errors["{$key}.{$field}"] = $msg;
                }
            } else {
                $row['sku'] = strtoupper($sku);
                $row['mrp'] = $mrp;
                $row['selling_price'] = $sellingPrice;
                $row['stock'] = (int) $stockRaw;
                $row['status'] = $status;
                $validRows[$key] = $row;

                if ($status === 'active') {
                    $activeValidCount++;
                }
            }
        }

        // 8. At least one valid active variant check
        if ($requireAtLeastOneActive && empty($errors) && $activeValidCount === 0) {
            $errors['general'] = 'At least one valid active variant offer is required for the product.';
        }

        return [
            'validRows' => $validRows,
            'errors' => $errors,
            'ignoredCount' => $ignoredCount,
        ];
    }
}
