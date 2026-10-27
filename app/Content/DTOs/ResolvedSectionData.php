<?php

namespace App\Content\DTOs;

readonly class ResolvedSectionData
{
    /**
     * @param  array<string, mixed>  $settings
     * @param  array<int, ResolvedItemData>  $items
     */
    public function __construct(
        public int $id,
        public string $type,
        public int $position,
        public string $visibility, // 'all', 'desktop', 'mobile'
        public array $settings,
        public array $items = [],
        public bool $isEager = false,
        public ?string $startsAt = null,
        public ?string $endsAt = null,
    ) {}
}
