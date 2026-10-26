<?php

namespace App\Checkout\DTOs;

readonly class CheckoutRequest
{
    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    public function __construct(
        public int $userId,
        public string $source,
        public array $lines,
        public ?string $intentId = null,
    ) {}
}
