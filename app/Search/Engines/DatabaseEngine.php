<?php

namespace App\Search\Engines;

use App\Search\Contracts\SearchEngineInterface;
use App\Search\DTOs\SearchQuery;
use App\Search\DTOs\SearchResult;
use App\Search\Models\SearchDocument;
use App\Search\Services\QueryNormalizer;

/**
 * Search documents in the search_documents table, matched with LIKE.
 * Every query word must start a word (or follow a "-") in the title or
 * keywords, so "pho" finds "phone" but not "sophisticated"; title matches
 * score higher. No typo tolerance.
 */
class DatabaseEngine implements SearchEngineInterface
{
    public function __construct(
        protected QueryNormalizer $normalizer,
    ) {}

    public function upsert(array $documents): void
    {
        if ($documents === []) {
            return;
        }

        $now = now();
        $rows = array_map(fn (array $document) => [
            'product_id' => (int) $document['id'],
            'title' => $this->normalizer->normalize((string) ($document['title'] ?? '')),
            'keywords' => $this->keywords($document),
            'in_stock' => (bool) ($document['in_stock'] ?? false),
            'document' => json_encode($document, JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ], $documents);

        SearchDocument::query()->upsert(
            $rows,
            ['product_id'],
            ['title', 'keywords', 'in_stock', 'document', 'updated_at'],
        );
    }

    public function delete(array $productIds): void
    {
        if ($productIds !== []) {
            SearchDocument::query()->whereIn('product_id', $productIds)->delete();
        }
    }

    public function flush(): void
    {
        SearchDocument::query()->delete();
    }

    public function search(SearchQuery $query): SearchResult
    {
        $words = $this->normalizer->words($query->text);
        if ($words === []) {
            return SearchResult::empty($query->text);
        }

        $builder = SearchDocument::query()->toBase()->select('product_id');
        $scores = [];
        $bindings = [];

        foreach ($words as $word) {
            $escaped = $this->escapeLike($word);
            $patterns = ["{$escaped}%", "% {$escaped}%", "%-{$escaped}%"];
            $titleMatch = "(title LIKE ? ESCAPE '!' OR title LIKE ? ESCAPE '!' OR title LIKE ? ESCAPE '!')";
            $keywordsMatch = "(keywords LIKE ? ESCAPE '!' OR keywords LIKE ? ESCAPE '!' OR keywords LIKE ? ESCAPE '!')";

            $builder->whereRaw("({$titleMatch} OR {$keywordsMatch})", [...$patterns, ...$patterns]);

            $scores[] = "(CASE WHEN title LIKE ? ESCAPE '!' THEN 5 WHEN {$titleMatch} THEN 4 WHEN {$keywordsMatch} THEN 1 ELSE 0 END)";
            array_push($bindings, "{$escaped}%", ...$patterns, ...$patterns);
        }

        if (count($words) > 1) {
            $scores[] = "(CASE WHEN title LIKE ? ESCAPE '!' THEN 5 ELSE 0 END)";
            $bindings[] = '%'.$this->escapeLike(implode(' ', $words)).'%';
        }

        $productIds = $builder
            ->selectRaw('('.implode(' + ', $scores).') AS score', $bindings)
            ->orderByDesc('score')
            ->orderByDesc('in_stock')
            ->orderByDesc('product_id')
            ->limit($query->limit)
            ->pluck('product_id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return new SearchResult(
            query: $query->text,
            productIds: $productIds,
            total: count($productIds),
        );
    }

    /**
     * Normalised searchable text other than the title.
     *
     * @param  array<string, mixed>  $document
     */
    protected function keywords(array $document): string
    {
        $parts = [
            $document['brand'] ?? null,
            $document['vertical'] ?? null,
            ...(array) ($document['categories'] ?? []),
            ...(array) ($document['attributes'] ?? []),
            ...(array) ($document['specs'] ?? []),
            ...(array) ($document['highlights'] ?? []),
            ...(array) ($document['codes'] ?? []),
        ];

        return $this->normalizer->normalize(implode(' ', array_filter($parts, 'is_string')));
    }

    protected function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }
}
