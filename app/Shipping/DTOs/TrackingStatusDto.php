<?php

namespace App\Shipping\DTOs;

use App\Shipping\Enums\ShipmentStatus;
use DateTimeInterface;

final readonly class TrackingStatusDto
{
    /**
     * @param  array<string, mixed>|null  $rawResponse
     * @param  string|null  $providerEventId  Courier-assigned id of this scan/event, when the courier sends one
     */
    public function __construct(
        public string $awb,
        public ShipmentStatus $status,
        public string $providerStatus,
        public ?string $providerNdrStatus = null,
        public ?string $location = null,
        public ?string $description = null,
        public ?DateTimeInterface $occurredAt = null,
        public ?DateTimeInterface $estimatedDeliveryAt = null,
        public ?DateTimeInterface $pickedUpAt = null,
        public ?array $rawResponse = null,
        public ?string $providerEventId = null,
    ) {}
}
