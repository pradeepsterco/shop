<?php

namespace App\Content\DTOs;

readonly class ResolvedLinkData
{
    public function __construct(
        public ?string $url,
        public bool $isValid,
        public bool $isExternal,
        public ?string $target = null,
        public ?string $rel = null,
        public ?string $error = null,
    ) {}
}
