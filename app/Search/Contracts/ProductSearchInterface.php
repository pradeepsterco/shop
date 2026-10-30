<?php

namespace App\Search\Contracts;

use App\Search\DTOs\SearchResult;

interface ProductSearchInterface
{
    /**
     * Search searchable products. Returns ordered product IDs only: callers load
     * prices and stock from the database so they are always live.
     */
    public function search(string $text): SearchResult;
}
