<?php

namespace App\Sellers\Contracts;

use App\Sellers\DTOs\SellerPayoutDetailsDto;

interface SellerPayoutLookupInterface
{
    /**
     * Get seller payout bank details with masked account number and verification status.
     */
    public function getSellerPayoutDetails(int $sellerProfileId): ?SellerPayoutDetailsDto;

    /**
     * Get the full decrypted bank account number exclusively for authorized CSV export.
     */
    public function getFullAccountNumberForExport(int $sellerProfileId): ?string;

    /**
     * Get current bank fingerprint to detect post-batch modifications.
     */
    public function getSellerBankFingerprint(int $sellerProfileId): ?string;
}
