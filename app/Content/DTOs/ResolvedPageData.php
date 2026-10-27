<?php

namespace App\Content\DTOs;

readonly class ResolvedPageData
{
    /**
     * @param  array<int, ResolvedSectionData>  $sections
     */
    public function __construct(
        public int $id,
        public string $slug,
        public string $title,
        public string $type,
        public ?string $seoTitle,
        public ?string $seoDescription,
        public array $sections = [],
    ) {}
}
