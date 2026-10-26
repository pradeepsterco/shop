<?php

namespace App\Orders\DTOs;

final readonly class SellerOrderShippingDetailsDto
{
    /**
     * @param  list<ShippingItemDto>  $items
     */
    public function __construct(
        public string $sellerOrderId,
        public string $orderId,
        public string $orderNumber,
        public int $sellerProfileId,
        public string $sellerStoreName,
        public ?string $sellerGstin,
        public ?string $sellerPhone,
        public ?string $sellerPickupAddress1,
        public ?string $sellerPickupAddress2,
        public ?string $sellerPickupCity,
        public ?string $sellerPickupState,
        public ?string $sellerPickupPostalCode,
        public string $customerName,
        public string $customerPhone,
        public ?string $customerAlternatePhone,
        public string $customerAddress1,
        public ?string $customerAddress2,
        public string $customerCity,
        public string $customerState,
        public string $customerPostalCode,
        public bool $isCod,
        public int $codAmountPaise,
        public int $grandTotalPaise,
        public array $items,
        public int $estimatedTotalWeightGrams,
    ) {}
}
