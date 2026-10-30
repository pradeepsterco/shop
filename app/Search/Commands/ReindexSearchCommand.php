<?php

namespace App\Search\Commands;

use App\Models\Product;
use App\Search\Contracts\SearchIndexerInterface;
use Illuminate\Console\Command;

class ReindexSearchCommand extends Command
{
    protected $signature = 'search:reindex
                            {--fresh : Remove every document before rebuilding}';

    protected $description = 'Rebuild the product search index';

    public function handle(SearchIndexerInterface $indexer): int
    {
        $bar = $this->output->createProgressBar(Product::query()->count());
        $bar->start();

        $indexed = $indexer->rebuild((bool) $this->option('fresh'), fn (int $processed) => $bar->advance($processed));

        $bar->finish();
        $this->newLine(2);
        $this->info("Indexed {$indexed} searchable products.");

        return self::SUCCESS;
    }
}
