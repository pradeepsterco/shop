<?php

namespace App\Search\Services;

use App\Models\Product;
use App\Search\Contracts\SearchEngineInterface;
use App\Search\Contracts\SearchIndexerInterface;
use App\Search\Jobs\ReindexProduct;
use Illuminate\Support\Collection;

class SearchIndexer implements SearchIndexerInterface
{
    public function __construct(
        protected SearchEngineInterface $engine,
        protected ProductDocumentBuilder $builder,
    ) {}

    public function queue(int $productId): void
    {
        if (! config('search.indexing_enabled', true)) {
            return;
        }

        ReindexProduct::dispatch($productId)
            ->delay(now()->addSeconds((int) config('search.reindex_delay_seconds', 5)))
            ->afterCommit();
    }

    public function index(int $productId): void
    {
        $this->apply($this->builder->buildMany([$productId]));
    }

    public function rebuild(bool $fresh = false, ?callable $onChunk = null): int
    {
        if ($fresh) {
            $this->engine->flush();
        }

        $indexed = 0;

        Product::query()
            ->select('id')
            ->chunkById((int) config('search.rebuild_chunk_size', 100), function (Collection $products) use (&$indexed, $onChunk) {
                $indexed += $this->apply($this->builder->buildMany($products->pluck('id')->map(fn ($id) => (int) $id)->all()));

                if ($onChunk) {
                    $onChunk($products->count());
                }
            });

        return $indexed;
    }

    /**
     * Upsert built documents and remove products that are no longer searchable.
     *
     * @param  array<int, array<string, mixed>|null>  $documents  keyed by product ID
     * @return int number of documents upserted
     */
    protected function apply(array $documents): int
    {
        $searchable = array_values(array_filter($documents));
        $removed = array_keys(array_filter($documents, fn ($document) => $document === null));

        $this->engine->upsert($searchable);
        $this->engine->delete($removed);

        return count($searchable);
    }
}
