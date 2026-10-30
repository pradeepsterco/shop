<?php

namespace App\Search\Services;

class QueryNormalizer
{
    /**
     * Lowercase, strip punctuation (keeping "-" and "." inside words) and collapse spaces.
     */
    public function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s.\-]+/u', ' ', $text) ?? '';

        $words = array_filter(
            array_map(fn (string $word) => trim($word, '.-'), preg_split('/\s+/u', $text) ?: []),
            fn (string $word) => $word !== '',
        );

        return implode(' ', $words);
    }

    /**
     * Distinct words of a normalised query, capped at search.max_query_words.
     *
     * @return array<int, string>
     */
    public function words(string $normalized): array
    {
        if ($normalized === '') {
            return [];
        }

        $words = array_values(array_unique(explode(' ', $normalized)));

        return array_slice($words, 0, (int) config('search.max_query_words', 8));
    }
}
