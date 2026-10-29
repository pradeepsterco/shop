<?php

namespace App\Returns\DTOs;

use App\Returns\Enums\RtoAdminDecision;
use App\Returns\Enums\RtoConditionStatus;
use DateTimeInterface;

readonly class RtoIntakeReportDto
{
    /**
     * @param  array<string>|null  $photos
     */
    public function __construct(
        public string $id,
        public string $sellerOrderId,
        public string $shipmentId,
        public int $sellerProfileId,
        public RtoConditionStatus $conditionStatus,
        public ?string $notes,
        public ?array $photos,
        public DateTimeInterface $reportedAt,
        public RtoAdminDecision $adminDecision,
        public ?DateTimeInterface $adminDecidedAt = null,
        public ?int $adminId = null,
        public ?string $adminNotes = null,
    ) {}
}
