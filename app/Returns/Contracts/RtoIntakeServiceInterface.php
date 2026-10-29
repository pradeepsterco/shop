<?php

namespace App\Returns\Contracts;

use App\Returns\DTOs\RtoIntakeReportDto;

interface RtoIntakeServiceInterface
{
    /**
     * Submit condition inspection report by seller.
     *
     * @param  array<string>|null  $photos
     */
    public function submitRtoReport(
        string $sellerOrderId,
        string $conditionStatus,
        ?string $notes = null,
        ?array $photos = null,
    ): RtoIntakeReportDto;

    /**
     * Admin decision to restock or write off.
     */
    public function decideRtoReport(
        string $sellerOrderId,
        string $decision, // restocked, written_off
        int $adminId,
        ?string $adminNotes = null,
    ): RtoIntakeReportDto;
}
