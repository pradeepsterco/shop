<?php

namespace App\Fees\Contracts;

use App\Fees\DTOs\FeeBreakdownDto;
use App\Fees\DTOs\FeeQuoteInput;
use DateTimeInterface;

interface FeeCalculatorInterface
{
    /**
     * Calculate standalone fee quotation for a single item/listing.
     */
    public function quote(FeeQuoteInput $input): FeeBreakdownDto;

    /**
     * Calculate fees for a seller package containing one or more items.
     * Shipping fee is calculated once on package total weight and allocated by weight share.
     *
     * @param  array<int, FeeQuoteInput>  $items
     * @return array<int, FeeBreakdownDto>
     */
    public function calculateForSellerPackage(
        array $items,
        string $pickupState,
        ?string $pickupPincode,
        ?string $deliveryState,
        ?string $deliveryPincode,
        string $paymentMethod,
        bool $sellerHasPan,
        DateTimeInterface $effectiveDate,
    ): array;
}
