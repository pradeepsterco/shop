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
        public ?string $customerEmail = null,
    ) {}

    /**
     * The same package details limited to some units (a reship of undelivered items), with what the courier
     * should collect and the value to declare for those units.
     *
     * @param  array<int, int>  $units  order item id => units
     */
    public function forReship(array $units, int $codAmountPaise, int $declaredValuePaise): self
    {
        $items = [];
        foreach ($this->items as $item) {
            $count = $units[$item->orderItemId] ?? 0;
            if ($count <= 0) {
                continue;
            }

            $items[] = new ShippingItemDto(
                orderItemId: $item->orderItemId,
                listingId: $item->listingId,
                variantId: $item->variantId,
                title: $item->title,
                sku: $item->sku,
                quantity: $count,
                unitPricePaise: $item->unitPricePaise,
                taxRatePercent: $item->taxRatePercent,
                taxAmountPaise: $item->quantity > 0 ? intdiv($item->taxAmountPaise * $count, $item->quantity) : 0,
                weightGrams: $item->weightGrams,
                lengthCm: $item->lengthCm,
                widthCm: $item->widthCm,
                heightCm: $item->heightCm,
            );
        }

        return new self(
            sellerOrderId: $this->sellerOrderId,
            orderId: $this->orderId,
            orderNumber: $this->orderNumber,
            sellerProfileId: $this->sellerProfileId,
            sellerStoreName: $this->sellerStoreName,
            sellerGstin: $this->sellerGstin,
            sellerPhone: $this->sellerPhone,
            sellerPickupAddress1: $this->sellerPickupAddress1,
            sellerPickupAddress2: $this->sellerPickupAddress2,
            sellerPickupCity: $this->sellerPickupCity,
            sellerPickupState: $this->sellerPickupState,
            sellerPickupPostalCode: $this->sellerPickupPostalCode,
            customerName: $this->customerName,
            customerPhone: $this->customerPhone,
            customerAlternatePhone: $this->customerAlternatePhone,
            customerAddress1: $this->customerAddress1,
            customerAddress2: $this->customerAddress2,
            customerCity: $this->customerCity,
            customerState: $this->customerState,
            customerPostalCode: $this->customerPostalCode,
            isCod: $codAmountPaise > 0,
            codAmountPaise: $codAmountPaise,
            grandTotalPaise: $declaredValuePaise,
            items: $items,
            estimatedTotalWeightGrams: (int) array_sum(array_map(fn (ShippingItemDto $i) => $i->weightGrams * $i->quantity, $items)),
            customerEmail: $this->customerEmail,
        );
    }
}
