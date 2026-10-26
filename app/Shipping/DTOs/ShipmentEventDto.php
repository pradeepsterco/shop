<?php

namespace App\Shipping\DTOs;

use App\Shipping\Enums\ShipmentStatus;
use DateTimeInterface;

final readonly class ShipmentEventDto
{
    public function __construct(
        public string $id,
        public string $eventKey,
        public string $provider,
        public ShipmentStatus $status,
        public string $providerStatus,
        public ?string $providerNdrStatus = null,
        public ?string $location = null,
        public ?string $description = null,
        public ?DateTimeInterface $occurredAt = null,
        public ?DateTimeInterface $receivedAt = null,
    ) {}
}
