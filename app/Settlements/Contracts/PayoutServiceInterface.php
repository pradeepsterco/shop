<?php

namespace App\Settlements\Contracts;

use App\Settlements\DTOs\PayoutBatchCreateInput;
use App\Settlements\DTOs\PayoutBatchResultDto;

interface PayoutServiceInterface
{
    /**
     * Create weekly payout batch. Throws if another open batch exists.
     */
    public function createWeeklyPayoutBatch(PayoutBatchCreateInput $input): PayoutBatchResultDto;

    /**
     * Generate standard bank payout CSV. Refuses if bank details do not match fingerprint.
     */
    public function generateBankCsv(string $batchId): string;

    /**
     * Mark an individual payout as paid with UTR.
     */
    public function markPayoutPaid(string $payoutId, string $utr): void;

    /**
     * Mark an individual payout as failed with reason, reverting settlements to eligible.
     */
    public function markPayoutFailed(string $payoutId, string $failureReason): void;

    /**
     * Cancel an open batch, reverting all its settlements to eligible.
     */
    public function cancelBatch(string $batchId): void;
}
