<?php

namespace App\Shipping\DTOs;

final readonly class CreateShipmentResponseDto
{
    /**
     * @param  array<string, mixed>|null  $rawResponse
     */
    public function __construct(
        public bool $success,
        public ?string $awb,
        public ?string $vendorWbn,
        public ?string $publicTrackingUrl,
        public int $estimatedChargesPaise = 0,
        public ?string $errorMessage = null,
        public ?array $rawResponse = null,
    ) {}
}
