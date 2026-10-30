<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Search Engine
    |--------------------------------------------------------------------------
    |
    | Supported: "database", "fake". The engine is only ever referenced inside
    | the App\Search module; everything else talks to the contracts.
    |
    */

    'engine' => env('SEARCH_ENGINE', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Indexing
    |--------------------------------------------------------------------------
    |
    | When enabled, catalog changes queue a ReindexProduct job. The short delay
    | lets a burst of changes to one product collapse into a single reindex.
    |
    */

    'indexing_enabled' => (bool) env('SEARCH_INDEXING', true),

    'reindex_delay_seconds' => 5,

    'rebuild_chunk_size' => 100,

    /*
    |--------------------------------------------------------------------------
    | Results
    |--------------------------------------------------------------------------
    */

    'max_hits' => 1000,

    'max_query_words' => 8,

    /*
    |--------------------------------------------------------------------------
    | Suggestions
    |--------------------------------------------------------------------------
    */

    'suggestions' => [
        'min_chars' => 2,
        'cache_seconds' => 600,
        'products' => 4,
        'verticals' => 3,
        'categories' => 3,
    ],

];
