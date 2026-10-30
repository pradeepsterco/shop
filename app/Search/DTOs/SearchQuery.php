<?php

namespace App\Search\DTOs;

final readonly class SearchQuery
{
    public function __construct(
        public string $text,
        public int $limit,
    ) {}
}
