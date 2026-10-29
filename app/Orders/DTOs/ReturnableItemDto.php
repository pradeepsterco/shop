<?php

namespace App\Orders\DTOs;

final readonly class ReturnableItemDto
{
    public function __construct(
        public int $orderItemId,
        public string $title,
        public int $qty,
        public int $unitPricePaise,
        /** False for non-returnable verticals: only damaged/defective/wrong/missing claims are allowed. */
        public bool $isReturnable,
    ) {}
}
