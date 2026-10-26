<?php

namespace App\Settlements\DTOs;

use Carbon\CarbonImmutable;

final readonly class PayoutBatchCreateInput
{
    public function __construct(
        public CarbonImmutable $scheduledPayoutDate,
        public ?string $notes = null,
        public ?int $createdByAdminId = null,
    ) {}
}
