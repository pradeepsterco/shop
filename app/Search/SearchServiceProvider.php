<?php

namespace App\Search;

use App\Search\Commands\ReindexSearchCommand;
use App\Search\Contracts\ProductSearchInterface;
use App\Search\Contracts\SearchEngineInterface;
use App\Search\Contracts\SearchIndexerInterface;
use App\Search\Contracts\SearchSuggestionsInterface;
use App\Search\Engines\DatabaseEngine;
use App\Search\Engines\FakeEngine;
use App\Search\Services\ProductDocumentBuilder;
use App\Search\Services\ProductSearchService;
use App\Search\Services\QueryNormalizer;
use App\Search\Services\SearchIndexer;
use App\Search\Services\SuggestService;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class SearchServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/search.php', 'search');

        $this->app->singleton(QueryNormalizer::class);
        $this->app->bind(ProductDocumentBuilder::class);

        $this->app->singleton(SearchEngineInterface::class, function ($app) {
            return match (config('search.engine')) {
                'database' => $app->make(DatabaseEngine::class),
                'fake' => $app->make(FakeEngine::class),
                default => throw new InvalidArgumentException('Unsupported search engine ['.config('search.engine').'].'),
            };
        });

        $this->app->singleton(ProductSearchInterface::class, ProductSearchService::class);
        $this->app->singleton(SearchSuggestionsInterface::class, SuggestService::class);
        $this->app->singleton(SearchIndexerInterface::class, SearchIndexer::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                ReindexSearchCommand::class,
            ]);
        }
    }
}
