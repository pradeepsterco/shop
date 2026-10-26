<?php

namespace App\Sellers\DTOs;

final readonly class SellerPayoutDetailsDto
{
    public function __construct(
        public int $sellerProfileId,
        public string $storeName,
        public string $sellerName,
        public string $bankAccountHolder,
        public string $maskedAccountNumber,
        public string $ifsc,
        public ?string $pan = null,
        public string $status = 'approved',
        public bool $isBankDetailsVerified = true,
        public string $bankFingerprint = '',
        public bool $isAnonymized = false,
    ) {}
}
