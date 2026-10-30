<?php

namespace App\Search\Contracts;

use App\Search\DTOs\SearchQuery;
use App\Search\DTOs\SearchResult;

/**
 * Storage and retrieval of product search documents. Implementations live in
 * App\Search\Engines and are never used outside the Search module.
 */
interface SearchEngineInterface
{
    /**
     * Insert or replace documents (each has an integer "id" = product ID).
     *
     * @param  array<int, array<string, mixed>>  $documents
     */
    public function upsert(array $documents): void;

    /**
     * @param  array<int, int>  $productIds
     */
    public function delete(array $productIds): void;

    /**
     * Remove every document.
     */
    public function flush(): void;

    /**
     * Match a normalised query; product IDs come back best match first.
     */
    public function search(SearchQuery $query): SearchResult;
}
