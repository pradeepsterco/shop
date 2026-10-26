<?php

namespace App\Orders\Services;

use App\Orders\Contracts\SellerOrderShippingDetailsLookupInterface;
use App\Orders\DTOs\SellerOrderShippingDetailsDto;
use App\Orders\DTOs\ShippingItemDto;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Models\SellerOrder;

class SellerOrderShippingDetailsLookupService implements SellerOrderShippingDetailsLookupInterface
{
    public function getShippingDetails(string $sellerOrderId): ?SellerOrderShippingDetailsDto
    {
        /** @var SellerOrder|null $sellerOrder */
        $sellerOrder = SellerOrder::with([
            'order',
            'sellerProfile.user',
            'items.variant',
        ])->find($sellerOrderId);

        if (! $sellerOrder) {
            return null;
        }

        $order = $sellerOrder->order;
        $deliveryAddr = (array) ($order->delivery_address ?? []);
        $sellerProfile = $sellerOrder->sellerProfile;

        $items = [];
        $totalWeight = 0;

        foreach ($sellerOrder->items as $item) {
            $variant = $item->variant;
            $rawWeight = $variant?->weight;
            $weightGrams = 500;
            if ($rawWeight !== null && $rawWeight > 0) {
                // If weight <= 20, assume kg, otherwise grams
                $weightGrams = (int) ($rawWeight <= 20 ? round($rawWeight * 1000) : round($rawWeight));
            }
            $weightGrams = max(50, $weightGrams);

            $dims = is_array($variant?->dimensions) ? $variant->dimensions : [];
            $lengthCm = (int) max(1, $dims['length'] ?? 15);
            $widthCm = (int) max(1, $dims['width'] ?? 10);
            $heightCm = (int) max(1, $dims['height'] ?? 5);

            $totalWeight += ($weightGrams * $item->qty);

            $items[] = new ShippingItemDto(
                orderItemId: (int) $item->id,
                listingId: $item->listing_id ? (int) $item->listing_id : null,
                variantId: $item->product_variant_id ? (int) $item->product_variant_id : null,
                title: (string) $item->title,
                sku: $item->sku,
                quantity: (int) $item->qty,
                unitPricePaise: (int) $item->unit_price,
                taxRatePercent: (int) round($item->gst_rate),
                taxAmountPaise: (int) $item->gst_amount,
                weightGrams: $weightGrams,
                lengthCm: $lengthCm,
                widthCm: $widthCm,
                heightCm: $heightCm,
            );
        }

        $isCod = match ($order->payment_method) {
            PaymentMethod::CashOnDelivery => true,
        };
        $codAmountPaise = (int) $sellerOrder->grand_total;

        $sellerStoreName = $sellerProfile ? $sellerProfile->store_name : $sellerOrder->seller_store_name;
        $sellerGstin = $sellerProfile ? $sellerProfile->gstin : $sellerOrder->seller_gstin;
        $sellerPhone = $sellerProfile ? ($sellerProfile->support_phone ?: $sellerProfile->user->phone) : null;
        $sellerPickup1 = $sellerProfile ? $sellerProfile->pickup_address_line1 : null;
        $sellerPickup2 = $sellerProfile ? $sellerProfile->pickup_address_line2 : null;
        $sellerPickupCity = $sellerProfile ? $sellerProfile->pickup_city : null;
        $sellerPickupState = $sellerProfile ? $sellerProfile->pickup_state : $sellerOrder->seller_pickup_state;
        $sellerPickupPincode = $sellerProfile ? $sellerProfile->pickup_postal_code : null;

        return new SellerOrderShippingDetailsDto(
            sellerOrderId: (string) $sellerOrder->id,
            orderId: (string) $order->id,
            orderNumber: (string) $order->order_number,
            sellerProfileId: (int) $sellerOrder->seller_profile_id,
            sellerStoreName: (string) $sellerStoreName,
            sellerGstin: $sellerGstin,
            sellerPhone: $sellerPhone,
            sellerPickupAddress1: $sellerPickup1,
            sellerPickupAddress2: $sellerPickup2,
            sellerPickupCity: $sellerPickupCity,
            sellerPickupState: $sellerPickupState,
            sellerPickupPostalCode: $sellerPickupPincode,
            customerName: (string) ($deliveryAddr['name'] ?? 'Customer'),
            customerPhone: (string) ($deliveryAddr['phone'] ?? ''),
            customerAlternatePhone: ! empty($deliveryAddr['alternate_phone']) ? (string) $deliveryAddr['alternate_phone'] : null,
            customerAddress1: (string) ($deliveryAddr['address_line'] ?? ''),
            customerAddress2: ! empty($deliveryAddr['locality']) ? (string) $deliveryAddr['locality'] : null,
            customerCity: (string) ($deliveryAddr['city'] ?? ''),
            customerState: (string) ($deliveryAddr['state'] ?? $order->delivery_state ?? ''),
            customerPostalCode: (string) ($deliveryAddr['pincode'] ?? ''),
            isCod: $isCod,
            codAmountPaise: $codAmountPaise,
            grandTotalPaise: (int) $sellerOrder->grand_total,
            items: $items,
            estimatedTotalWeightGrams: max(100, $totalWeight),
        );
    }
}
