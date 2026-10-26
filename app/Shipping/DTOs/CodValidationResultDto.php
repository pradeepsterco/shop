<?php

namespace App\Shipping\DTOs;

final readonly class CodValidationResultDto
{
    /**
     * @param  list<string>  $failureReasons
     */
    public function __construct(
        public bool $isAvailable,
        public array $failureReasons = [],
    ) {}

    public function reasonMessage(): ?string
    {
        return empty($this->failureReasons) ? null : implode('; ', $this->failureReasons);
    }
}
