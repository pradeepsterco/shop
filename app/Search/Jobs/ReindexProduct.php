<?php

namespace App\Search\Jobs;

use App\Search\Contracts\SearchIndexerInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Rebuilds one product's search document from the database at run time, so a
 * burst of changes needs one run. Unique only until processing starts: a change
 * made while the job runs queues a fresh one.
 */
class ReindexProduct implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(
        public int $productId,
    ) {}

    public function uniqueId(): string
    {
        return (string) $this->productId;
    }

    public function handle(SearchIndexerInterface $indexer): void
    {
        $indexer->index($this->productId);
    }
}
