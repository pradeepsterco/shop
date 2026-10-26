<?php

namespace App\Shipping\DTOs;

final readonly class ServiceabilityResultDto
{
    /**
     * @param  array<string, mixed>|null  $rawResponse
     */
    public function __construct(
        public bool $serviceable,
        public bool $codAvailable,
        public int $maxCodAmountPaise,
        public ?string $city = null,
        public ?string $state = null,
        public ?int $tatMinDays = null,
        public ?int $tatMaxDays = null,
        public ?string $errorMessage = null,
        public ?array $rawResponse = null,
    ) {}
}
