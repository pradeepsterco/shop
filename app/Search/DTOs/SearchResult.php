<?php

namespace App\Search\DTOs;

final readonly class SearchResult
{
    /**
     * @param  array<int, int>  $productIds  Matching product IDs, best match first.
     * @param  array<string, array<string, int>>  $facets  Filter counts (filled by engines that support them).
     */
    public function __construct(
        public string $query,
        public array $productIds,
        public int $total,
        public ?string $correctedQuery = null,
        public array $facets = [],
        public bool $fromFallback = false,
    ) {}

    public static function empty(string $query = ''): self
    {
        return new self(query: $query, productIds: [], total: 0);
    }

    public function isEmpty(): bool
    {
        return $this->productIds === [];
    }
}
