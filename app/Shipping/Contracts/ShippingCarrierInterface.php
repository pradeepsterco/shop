<?php

namespace App\Shipping\Contracts;

use App\Orders\DTOs\SellerOrderInvoiceDto;
use App\Orders\DTOs\SellerOrderShippingDetailsDto;
use App\Sellers\DTOs\SellerPickupAddressDto;
use App\Shipping\DTOs\CreateShipmentRequestDto;
use App\Shipping\DTOs\CreateShipmentResponseDto;
use App\Shipping\DTOs\SellerWarehouseRegistrationDto;
use App\Shipping\DTOs\ServiceabilityResultDto;
use App\Shipping\DTOs\TrackingStatusDto;
use App\Shipping\Models\Shipment;

interface ShippingCarrierInterface
{
    /**
     * Get unique carrier driver name (e.g. 'ekart', 'manual', 'fake').
     */
    public function name(): string;

    /**
     * Check serviceability and COD limits for the destination pincode.
     */
    public function checkServiceability(string $pincode, ?string $pickupPincode = null): ServiceabilityResultDto;

    /**
     * Register a seller's pickup address / warehouse with the carrier.
     */
    public function registerPickupAddress(SellerPickupAddressDto $address): SellerWarehouseRegistrationDto;

    /**
     * List all registered addresses from the carrier.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRegisteredAddresses(): array;

    /**
     * Manifest / create a new shipment package with the carrier.
     */
    public function createShipment(
        CreateShipmentRequestDto $request,
        SellerOrderShippingDetailsDto $details,
        SellerOrderInvoiceDto $invoice,
    ): CreateShipmentResponseDto;

    /**
     * Book a return pickup: the courier collects $details' items from the customer (the order's delivery address)
     * and delivers them to the seller's pickup address. Nothing is collected from the customer.
     * $shipment lets step-based carriers persist progress so a retry resumes instead of re-creating.
     */
    public function createReverseShipment(
        CreateShipmentRequestDto $request,
        SellerOrderShippingDetailsDto $details,
        SellerOrderInvoiceDto $invoice,
        string $returnReason,
        ?Shipment $shipment = null,
    ): CreateShipmentResponseDto;

    /**
     * Download packing label binary PDF data for an AWB.
     */
    public function downloadLabel(string $awb): string;

    /**
     * Set or update preferred dispatch date for delayed dispatch shipments.
     *
     * @param  list<string>  $awbs
     */
    public function setDispatchDate(array $awbs, string $date): bool;

    /**
     * Cancel an existing shipment by tracking ID / AWB.
     */
    public function cancelShipment(string $awb): bool;

    /**
     * Request an NDR action ('reattempt' or 'rto') for an undelivered shipment.
     *
     * $action is "reattempt" or "rto". $payload keys (all optional, already validated by ShipmentService):
     * phone (10 digits), address, reattempt_date (Y-m-d, business timezone), instructions.
     * Phone, address and date are only sent for a re-attempt.
     *
     * @param  array<string, mixed>  $payload
     * @return array{success: bool, message: string}
     */
    public function requestNdrAction(string $awb, string $action, array $payload = []): array;

    /**
     * Track a shipment by tracking ID / AWB.
     */
    public function track(string $awb): TrackingStatusDto;
}
