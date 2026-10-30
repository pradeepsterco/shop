<?php

namespace App\Search\Contracts;

interface SearchIndexerInterface
{
    /**
     * Queue a reindex of one product (after the current transaction commits).
     * Safe to call often: repeated calls for the same product collapse into one job.
     */
    public function queue(int $productId): void;

    /**
     * Reindex one product now: upsert it, or remove it if it is no longer searchable.
     */
    public function index(int $productId): void;

    /**
     * Rebuild the whole index. Returns the number of searchable products indexed.
     *
     * @param  (callable(int): void)|null  $onChunk  Called with the number of products processed per chunk.
     */
    public function rebuild(bool $fresh = false, ?callable $onChunk = null): int;
}
