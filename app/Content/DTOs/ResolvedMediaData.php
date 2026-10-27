<?php

namespace App\Content\DTOs;

readonly class ResolvedMediaData
{
    /**
     * @param  array<string, mixed>  $variants
     */
    public function __construct(
        public int $id,
        public string $url,
        public ?string $webpUrl,
        public int $width,
        public int $height,
        public string $alt,
        public array $variants = [],
    ) {}
}
