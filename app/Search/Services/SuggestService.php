<?php

namespace App\Search\Services;

use App\Enums\ProductImageStatus;
use App\Models\Category;
use App\Models\Product;
use App\Models\Vertical;
use App\Search\Contracts\ProductSearchInterface;
use App\Search\Contracts\SearchSuggestionsInterface;
use Illuminate\Support\Facades\Cache;

class SuggestService implements SearchSuggestionsInterface
{
    public function __construct(
        protected ProductSearchInterface $search,
        protected QueryNormalizer $normalizer,
    ) {}

    public function suggest(string $text): array
    {
        $normalized = $this->normalizer->normalize($text);
        $empty = ['query' => $normalized, 'scopes' => [], 'categories' => [], 'products' => []];

        if (mb_strlen($normalized) < (int) config('search.suggestions.min_chars', 2)) {
            return $empty;
        }

        // Only labels, URLs and product IDs are cached; product titles, prices and images are loaded live.
        $cached = Cache::remember(
            'search_suggest_'.md5($normalized),
            (int) config('search.suggestions.cache_seconds', 600),
            fn () => $this->build($normalized),
        );

        return [
            'query' => $normalized,
            'scopes' => $cached['scopes'],
            'categories' => $cached['categories'],
            'products' => $this->products($cached['product_ids']),
        ];
    }

    /**
     * @return array{scopes: array<int, array{label: string, context: string, url: string}>, categories: array<int, array{label: string, context: ?string, url: string}>, product_ids: array<int, int>}
     */
    protected function build(string $normalized): array
    {
        $result = $this->search->search($normalized);

        return [
            'scopes' => $this->scopes($normalized, array_slice($result->productIds, 0, 50)),
            'categories' => $this->categories($normalized),
            'product_ids' => array_slice($result->productIds, 0, (int) config('search.suggestions.products', 4)),
        ];
    }

    /**
     * "{query} in {vertical}" for the verticals with the most matching products.
     *
     * @param  array<int, int>  $productIds
     * @return array<int, array{label: string, context: string, url: string}>
     */
    protected function scopes(string $normalized, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $verticalIds = Product::query()
            ->whereIntegerInRaw('id', $productIds)
            ->pluck('vertical_id')
            ->filter()
            ->countBy()
            ->sortDesc()
            ->keys()
            ->take((int) config('search.suggestions.verticals', 3))
            ->all();

        $verticals = Vertical::query()
            ->whereIn('id', $verticalIds)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');

        return collect($verticalIds)
            ->map(fn ($id) => $verticals->get($id))
            ->filter()
            ->map(fn (Vertical $vertical) => [
                'label' => $normalized,
                'context' => $vertical->name,
                'url' => route('products.index', ['q' => $normalized, 'vertical' => $vertical->slug]),
            ])
            ->values()
            ->all();
    }

    /**
     * Categories and verticals whose name contains the query.
     *
     * @return array<int, array{label: string, context: ?string, url: string}>
     */
    protected function categories(string $normalized): array
    {
        $limit = (int) config('search.suggestions.categories', 3);
        $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $normalized).'%';

        $categories = Category::query()
            ->with('parent')
            ->where('is_active', true)
            ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Category $category) => [
                'label' => $category->name,
                'context' => $category->parent?->name,
                'url' => route('products.index', ['category' => $category->slug]),
            ]);

        $verticals = Vertical::query()
            ->with('category')
            ->where('is_active', true)
            ->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like])
            ->orderBy('name')
            ->limit($limit)
            ->get()
            ->map(fn (Vertical $vertical) => [
                'label' => $vertical->name,
                'context' => $vertical->category?->name,
                'url' => route('products.index', ['vertical' => $vertical->slug]),
            ]);

        return $categories->concat($verticals)->take($limit)->values()->all();
    }

    /**
     * @param  array<int, int>  $productIds
     * @return array<int, array{id: int, title: string, price_paise: ?int, image_url: string, url: string}>
     */
    protected function products(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $products = Product::query()
            ->whereIntegerInRaw('id', $productIds)
            ->whereNotNull('buy_box_listing_id')
            ->with([
                'images' => fn ($q) => $q->where('status', ProductImageStatus::Live)->orderBy('sort_order'),
                'buyBoxListing.variant',
            ])
            ->get()
            ->keyBy('id');

        return collect($productIds)
            ->map(fn (int $id) => $products->get($id))
            ->filter(fn (?Product $product) => $product?->buyBoxListing?->variant?->upid !== null)
            ->map(function (Product $product) {
                $image = $product->images->first(fn ($img) => $img->is_primary) ?? $product->images->first();

                return [
                    'id' => (int) $product->id,
                    'title' => (string) $product->title,
                    'price_paise' => $product->min_price !== null ? (int) $product->min_price : null,
                    'image_url' => $image?->url() ?? asset('storage/products/placeholder.svg'),
                    'url' => route('product.show', ['slug' => $product->slug, 'upid' => $product->buyBoxListing->variant->upid]),
                ];
            })
            ->values()
            ->all();
    }
}
