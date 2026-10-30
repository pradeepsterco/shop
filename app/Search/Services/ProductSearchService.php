<?php

namespace App\Search\Services;

use App\Enums\ProductQcStatus;
use App\Models\Product;
use App\Search\Contracts\ProductSearchInterface;
use App\Search\Contracts\SearchEngineInterface;
use App\Search\DTOs\SearchQuery;
use App\Search\DTOs\SearchResult;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProductSearchService implements ProductSearchInterface
{
    public function __construct(
        protected SearchEngineInterface $engine,
        protected QueryNormalizer $normalizer,
    ) {}

    public function search(string $text): SearchResult
    {
        $normalized = $this->normalizer->normalize($text);
        if ($normalized === '') {
            return SearchResult::empty();
        }

        try {
            return $this->engine->search(new SearchQuery($normalized, $this->maxHits()));
        } catch (Throwable $e) {
            Log::warning('Search engine failed, falling back to database search', [
                'query' => $normalized,
                'error' => $e->getMessage(),
            ]);

            return $this->fallback($normalized);
        }
    }

    /**
     * Live LIKE search on title and brand name, used only while the engine is down.
     */
    protected function fallback(string $normalized): SearchResult
    {
        $query = Product::query()
            ->where('qc_status', ProductQcStatus::Approved)
            ->whereNotNull('buy_box_listing_id');

        foreach ($this->normalizer->words($normalized) as $word) {
            $like = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $word).'%';

            $query->where(function ($q) use ($like) {
                $q->whereRaw("LOWER(title) LIKE ? ESCAPE '!'", [$like])
                    ->orWhereHas('brand', fn ($brand) => $brand->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$like]));
            });
        }

        $productIds = $query
            ->orderByDesc('in_stock')
            ->orderByDesc('id')
            ->limit($this->maxHits())
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return new SearchResult(
            query: $normalized,
            productIds: $productIds,
            total: count($productIds),
            fromFallback: true,
        );
    }

    protected function maxHits(): int
    {
        return (int) config('search.max_hits', 1000);
    }
}
