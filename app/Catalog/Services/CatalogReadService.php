<?php

namespace App\Catalog\Services;

use App\Catalog\Contracts\CatalogReadInterface;
use App\Enums\BrandStatus;
use App\Enums\ProductQcStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;

class CatalogReadService implements CatalogReadInterface
{
    /**
     * Find category by ID or slug.
     */
    public function findCategory(int|string $identifier): ?array
    {
        $category = is_numeric($identifier)
            ? Category::find((int) $identifier)
            : Category::where('slug', (string) $identifier)->first();

        if (! $category) {
            return null;
        }

        return [
            'id' => (int) $category->id,
            'name' => (string) $category->name,
            'slug' => (string) $category->slug,
            'is_active' => (bool) $category->is_active,
        ];
    }

    /**
     * Find brand by ID or slug.
     */
    public function findBrand(int|string $identifier): ?array
    {
        $brand = is_numeric($identifier)
            ? Brand::find((int) $identifier)
            : Brand::where('slug', (string) $identifier)->first();

        if (! $brand) {
            return null;
        }

        $isActive = ($brand->status === BrandStatus::Approved);
        $logoUrl = $brand->logo_path ? asset('storage/'.ltrim($brand->logo_path, '/')) : null;

        return [
            'id' => (int) $brand->id,
            'name' => (string) $brand->name,
            'slug' => (string) $brand->slug,
            'logo_url' => $logoUrl,
            'is_active' => $isActive,
        ];
    }

    /**
     * Find product by ID, slug, or UPID.
     */
    public function findProduct(int|string $identifier): ?array
    {
        $product = null;

        if (is_numeric($identifier)) {
            $product = Product::with(['images', 'variants'])->find((int) $identifier);
        } else {
            // Check if string matches UPID pattern or product variant
            $variant = ProductVariant::with(['product.images', 'product.variants'])->where('upid', (string) $identifier)->first();
            if ($variant) {
                $product = $variant->product;
            } else {
                $product = Product::with(['images', 'variants'])->where('slug', (string) $identifier)->first();
            }
        }

        if (! $product) {
            return null;
        }

        $primaryImage = $product->images->where('is_primary', true)->first()
            ?? $product->images->first();

        $primaryVariant = $product->variants->first();
        $upid = $primaryVariant?->upid;

        $isActive = ($product->qc_status === ProductQcStatus::Approved);

        return [
            'id' => (int) $product->id,
            'title' => (string) $product->title,
            'slug' => (string) $product->slug,
            'upid' => $upid ? (string) $upid : null,
            'mrp' => $product->buy_box_mrp ? (int) $product->buy_box_mrp : null,
            'selling_price' => $product->min_price ? (int) $product->min_price : null,
            'discount_percent' => $product->discount_percent ? (int) $product->discount_percent : null,
            'image_url' => $primaryImage ? $primaryImage->url() : null,
            'is_active' => $isActive,
            'in_stock' => (bool) $product->in_stock,
        ];
    }

    /**
     * Search categories by keyword.
     */
    public function searchCategories(string $query, int $limit = 10): array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return [];
        }

        return Category::query()
            ->where('name', 'like', "%{$trimmed}%")
            ->orWhere('slug', 'like', "%{$trimmed}%")
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'slug', 'is_active'])
            ->map(fn (Category $c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'slug' => (string) $c->slug,
                'is_active' => (bool) $c->is_active,
            ])
            ->all();
    }

    /**
     * Get active categories.
     *
     * @return array<int, array{id: int, name: string, slug: string, is_active: bool}>
     */
    public function getActiveCategories(int $limit = 10): array
    {
        return Category::query()
            ->where('is_active', true)
            ->whereNull('parent_id')
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'slug', 'is_active'])
            ->map(fn (Category $c) => [
                'id' => (int) $c->id,
                'name' => (string) $c->name,
                'slug' => (string) $c->slug,
                'is_active' => (bool) $c->is_active,
            ])
            ->all();
    }

    /**
     * Search brands by keyword.
     */
    public function searchBrands(string $query, int $limit = 10): array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return [];
        }

        return Brand::query()
            ->where('name', 'like', "%{$trimmed}%")
            ->orWhere('slug', 'like', "%{$trimmed}%")
            ->orderBy('name')
            ->limit($limit)
            ->get(['id', 'name', 'slug', 'status'])
            ->map(fn (Brand $b) => [
                'id' => (int) $b->id,
                'name' => (string) $b->name,
                'slug' => (string) $b->slug,
                'is_active' => ($b->status === BrandStatus::Approved),
            ])
            ->all();
    }

    /**
     * Search products by keyword (title, slug, upid).
     */
    public function searchProducts(string $query, int $limit = 10): array
    {
        $trimmed = trim($query);
        if ($trimmed === '') {
            return [];
        }

        return Product::query()
            ->with('variants')
            ->where(function ($q) use ($trimmed) {
                $q->where('title', 'like', "%{$trimmed}%")
                    ->orWhere('slug', 'like', "%{$trimmed}%")
                    ->orWhereHas('variants', fn ($v) => $v->where('upid', 'like', "%{$trimmed}%"));
            })
            ->limit($limit)
            ->get()
            ->map(fn (Product $p) => [
                'id' => (int) $p->id,
                'title' => (string) $p->title,
                'slug' => (string) $p->slug,
                'upid' => $p->variants->first()?->upid,
                'is_active' => ($p->qc_status === ProductQcStatus::Approved),
            ])
            ->all();
    }
}
