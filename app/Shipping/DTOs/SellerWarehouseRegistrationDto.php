<?php

namespace App\Shipping\DTOs;

final readonly class SellerWarehouseRegistrationDto
{
    /**
     * @param  array<string, mixed>|null  $rawResponse
     */
    public function __construct(
        public bool $success,
        public string $alias,
        public ?string $message = null,
        public ?array $rawResponse = null,
    ) {}
}
