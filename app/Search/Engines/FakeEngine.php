<?php

namespace App\Search\Engines;

use App\Search\Contracts\SearchEngineInterface;
use App\Search\DTOs\SearchQuery;
use App\Search\DTOs\SearchResult;
use App\Search\Services\QueryNormalizer;
use RuntimeException;

/**
 * In-memory engine for tests. Set $failSearches to simulate an engine outage.
 */
class FakeEngine implements SearchEngineInterface
{
    /** @var array<int, array<string, mixed>> keyed by product ID */
    public array $documents = [];

    public bool $failSearches = false;

    public function __construct(
        protected QueryNormalizer $normalizer,
    ) {}

    public function upsert(array $documents): void
    {
        foreach ($documents as $document) {
            $this->documents[(int) $document['id']] = $document;
        }
    }

    public function delete(array $productIds): void
    {
        foreach ($productIds as $productId) {
            unset($this->documents[(int) $productId]);
        }
    }

    public function flush(): void
    {
        $this->documents = [];
    }

    public function search(SearchQuery $query): SearchResult
    {
        if ($this->failSearches) {
            throw new RuntimeException('Fake search engine is unavailable.');
        }

        $words = $this->normalizer->words($query->text);
        $matches = [];

        foreach ($this->documents as $id => $document) {
            $title = $this->normalizer->normalize((string) ($document['title'] ?? ''));
            $text = $title.' '.$this->normalizer->normalize(json_encode($document) ?: '');

            if ($words !== [] && collect($words)->every(fn (string $word) => str_contains($text, $word))) {
                $titleHits = collect($words)->filter(fn (string $word) => str_contains($title, $word))->count();
                $matches[] = ['id' => $id, 'score' => $titleHits, 'in_stock' => (bool) ($document['in_stock'] ?? false)];
            }
        }

        usort($matches, fn (array $a, array $b) => [$b['score'], $b['in_stock'], $b['id']] <=> [$a['score'], $a['in_stock'], $a['id']]);

        $productIds = array_slice(array_column($matches, 'id'), 0, $query->limit);

        return new SearchResult(query: $query->text, productIds: $productIds, total: count($productIds));
    }
}
