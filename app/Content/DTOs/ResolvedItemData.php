<?php

namespace App\Content\DTOs;

readonly class ResolvedItemData
{
    /**
     * @param  array<string, mixed>  $settings
     */
    public function __construct(
        public int $id,
        public ?ResolvedMediaData $desktopMedia,
        public ?ResolvedMediaData $mobileMedia,
        public ?ResolvedLinkData $link,
        public array $settings = [],
    ) {}
}
