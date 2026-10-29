<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Product Reviews Feature Switch
    |--------------------------------------------------------------------------
    |
    | When disabled, review sections and rating pills render nothing on
    | the storefront and all review submissions are rejected cleanly.
    |
    */
    'enabled' => (bool) env('REVIEWS_ENABLED', true),

    /*
    |--------------------------------------------------------------------------
    | Reviews Pagination
    |--------------------------------------------------------------------------
    |
    | Default number of approved reviews shown per page on product detail pages.
    |
    */
    'per_page' => 5,

    /*
    |--------------------------------------------------------------------------
    | Content Validation Lengths
    |--------------------------------------------------------------------------
    |
    | Minimum and maximum character lengths for review titles and comments.
    |
    */
    'min_comment_length' => 10,
    'max_comment_length' => 2000,
    'max_title_length' => 120,
];
