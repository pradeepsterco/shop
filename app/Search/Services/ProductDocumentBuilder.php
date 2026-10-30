<?php

namespace App\Search\Services;

use App\Enums\ListingStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\VerticalAttribute;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Turns catalog products into search documents (one per product).
 */
class ProductDocumentBuilder
{
    /** @var array<int, array<int, array{id: int, name: string, slug: string}>> */
    protected array $categoryTrails = [];

    /**
     * Build documents for the given products. Products that are not searchable
     * (not approved, or without a buy box) map to null.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array<string, mixed>|null> keyed by product ID
     */
    public function buildMany(array $productIds): array
    {
        $documents = array_fill_keys($productIds, null);

        if ($productIds === []) {
            return $documents;
        }

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->where('qc_status', ProductQcStatus::Approved)
            ->whereNotNull('buy_box_listing_id')
            ->with([
                'brand',
                'vertical.category',
                'variants' => fn ($q) => $q->where('qc_status', VariantQcStatus::Approved)
                    ->whereHas('listings', fn (Builder $listing) => $listing->where('status', ListingStatus::Active)
                        ->whereHas('sellerProfile', fn (Builder $seller) => $seller->where('status', SellerStatus::Approved))),
            ])
            ->get();

        $attributesByVertical = VerticalAttribute::query()
            ->whereIn('vertical_id', $products->pluck('vertical_id')->filter()->unique()->all())
            ->get()
            ->groupBy('vertical_id');

        foreach ($products as $product) {
            $attributes = ($attributesByVertical->get($product->vertical_id) ?? collect())->keyBy('code');
            $documents[$product->id] = $this->build($product, $attributes);
        }

        return $documents;
    }

    /**
     * @param  Collection<string, VerticalAttribute>  $attributes  keyed by code
     * @return array<string, mixed>
     */
    protected function build(Product $product, Collection $attributes): array
    {
        $vertical = $product->vertical;
        $trail = $vertical?->category ? $this->categoryTrail($vertical->category) : [];

        $attrs = [];
        $attributeLabels = [];
        $codes = [];

        foreach ($product->variants as $variant) {
            foreach ((array) $variant->variant_attributes as $code => $value) {
                if (! is_scalar($value) || (string) $value === '') {
                    continue;
                }

                $attrs[(string) $code][] = (string) $value;
                $attributeLabels[] = $this->optionLabel($attributes->get($code), (string) $value);
            }

            $codes[] = $variant->upid;
            $codes[] = $variant->barcode;
        }

        $specLabels = [];
        foreach ((array) $product->specs as $code => $value) {
            foreach ((array) $value as $single) {
                if (is_scalar($single) && (string) $single !== '') {
                    $specLabels[] = $this->optionLabel($attributes->get($code), (string) $single);
                }
            }
        }

        return [
            'id' => (int) $product->id,
            'title' => (string) $product->title,
            'brand' => $product->brand?->name,
            'brand_slug' => $product->brand?->slug,
            'vertical' => $vertical?->name,
            'vertical_id' => $vertical?->id,
            'categories' => array_column($trail, 'name'),
            'category_ids' => array_column($trail, 'id'),
            'attributes' => $this->distinct($attributeLabels),
            'specs' => $this->distinct($specLabels),
            'highlights' => $this->distinct(array_filter((array) $product->highlights, 'is_string')),
            'codes' => $this->distinct($codes),
            'attrs' => array_map(fn (array $values) => $this->distinct($values), $attrs),
            'min_price' => $product->min_price !== null ? (int) $product->min_price : null,
            'discount_percent' => (int) $product->discount_percent,
            'in_stock' => (bool) $product->in_stock,
            'created_at' => $product->created_at?->getTimestamp(),
        ];
    }

    /**
     * Root-to-leaf category trail, memoised per category for bulk builds.
     *
     * @return array<int, array{id: int, name: string, slug: string}>
     */
    protected function categoryTrail(Category $category): array
    {
        return $this->categoryTrails[$category->id] ??= $category->breadcrumbs();
    }

    protected function optionLabel(?VerticalAttribute $attribute, string $value): string
    {
        foreach ((array) $attribute?->options as $option) {
            if ((string) $option['value'] === $value && $option['label'] !== '') {
                return $option['label'];
            }
        }

        return $value;
    }

    /**
     * @param  array<int, mixed>  $values
     * @return array<int, string>
     */
    protected function distinct(array $values): array
    {
        return array_values(array_unique(array_filter(
            array_map(fn ($value) => is_scalar($value) ? trim((string) $value) : '', $values),
            fn (string $value) => $value !== '',
        )));
    }
}
