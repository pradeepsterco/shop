<?php

namespace App\Search\Contracts;

interface SearchSuggestionsInterface
{
    /**
     * Suggestions for the search box.
     *
     * @return array{
     *     query: string,
     *     scopes: array<int, array{label: string, context: string, url: string}>,
     *     categories: array<int, array{label: string, context: ?string, url: string}>,
     *     products: array<int, array{id: int, title: string, price_paise: ?int, image_url: string, url: string}>
     * }
     */
    public function suggest(string $text): array;
}
