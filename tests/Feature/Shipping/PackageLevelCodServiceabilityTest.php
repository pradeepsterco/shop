<?php

use App\Orders\DTOs\SellerOrderInvoiceDto;
use App\Orders\DTOs\SellerOrderShippingDetailsDto;
use App\Sellers\DTOs\SellerPickupAddressDto;
use App\Shipping\Contracts\CarrierManagerInterface;
use App\Shipping\Contracts\DeliveryServiceabilityLookupInterface;
use App\Shipping\Contracts\ShippingCarrierInterface;
use App\Shipping\Drivers\FakeCarrierDriver;
use App\Shipping\DTOs\CreateShipmentRequestDto;
use App\Shipping\DTOs\CreateShipmentResponseDto;
use App\Shipping\DTOs\SellerWarehouseRegistrationDto;
use App\Shipping\DTOs\ServiceabilityResultDto;
use App\Shipping\DTOs\TrackingStatusDto;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    config(['shipping.default' => 'fake']);

    $carrierManager = app(CarrierManagerInterface::class);
    $this->fakeDriver = new FakeCarrierDriver;
    $carrierManager->setDriverInstance('fake', $this->fakeDriver);
    $carrierManager->setActiveDriver('fake');
});

test('a ₹60,000 order split into two ₹30,000 packages is allowed when pincode allows', function () {
    // Pincode 560001 allows up to 40,000 COD per package
    $this->fakeDriver->serviceabilityMap['560001'] = new ServiceabilityResultDto(
        serviceable: true,
        codAvailable: true,
        maxCodAmountPaise: 4000000, // ₹40,000
        city: 'Bengaluru',
        state: 'Karnataka',
    );

    $lookup = app(DeliveryServiceabilityLookupInterface::class);

    $packages = [
        [
            'seller_profile_id' => 1,
            'seller_store_name' => 'Toy Galaxy',
            'cod_amount_paise' => 3000000, // ₹30,000
            'delivery_pincode' => '560001',
        ],
        [
            'seller_profile_id' => 2,
            'seller_store_name' => 'Brick Wonders',
            'cod_amount_paise' => 3000000, // ₹30,000
            'delivery_pincode' => '560001',
        ],
    ];

    $result = $lookup->validatePackageCodLimits($packages);

    expect($result->isAvailable)->toBeTrue()
        ->and($result->failureReasons)->toBeEmpty();
});

test('a package exceeding the pincode COD limit blocks COD and provides clear reason', function () {
    // Pincode 560001 only allows up to ₹25,000 COD
    $this->fakeDriver->serviceabilityMap['560001'] = new ServiceabilityResultDto(
        serviceable: true,
        codAvailable: true,
        maxCodAmountPaise: 2500000, // ₹25,000
        city: 'Bengaluru',
        state: 'Karnataka',
    );

    $lookup = app(DeliveryServiceabilityLookupInterface::class);

    $packages = [
        [
            'seller_profile_id' => 1,
            'seller_store_name' => 'Toy Galaxy',
            'cod_amount_paise' => 3000000, // ₹30,000 -> exceeds ₹25,000 limit
            'delivery_pincode' => '560001',
        ],
    ];

    $result = $lookup->validatePackageCodLimits($packages);

    expect($result->isAvailable)->toBeFalse()
        ->and($result->reasonMessage())->toContain("Package from 'Toy Galaxy' amount (₹30,000) exceeds maximum COD limit (₹25,000) for pincode 560001");
});

test('a package exceeding the carrier hard limit of ₹49,999 blocks COD even if pincode allows higher', function () {
    // Even if pincode serviceability returned ₹60,000, hard limit is ₹49,999
    $this->fakeDriver->serviceabilityMap['560001'] = new ServiceabilityResultDto(
        serviceable: true,
        codAvailable: true,
        maxCodAmountPaise: 7000000, // ₹70,000
        city: 'Bengaluru',
        state: 'Karnataka',
    );

    $lookup = app(DeliveryServiceabilityLookupInterface::class);

    $packages = [
        [
            'seller_profile_id' => 1,
            'seller_store_name' => 'Toy Galaxy',
            'cod_amount_paise' => 5500000, // ₹55,000 -> exceeds ₹49,999
            'delivery_pincode' => '560001',
        ],
    ];

    $result = $lookup->validatePackageCodLimits($packages);

    expect($result->isAvailable)->toBeFalse()
        ->and($result->reasonMessage())->toContain("Package from 'Toy Galaxy' amount (₹55,000) exceeds maximum COD limit (₹49,999) for pincode 560001");
});

test('if any package is in an unserviceable or COD-disabled pincode, COD is blocked', function () {
    $this->fakeDriver->serviceabilityMap['110001'] = new ServiceabilityResultDto(
        serviceable: true,
        codAvailable: false,
        maxCodAmountPaise: 0,
        city: 'New Delhi',
        state: 'Delhi',
    );

    $lookup = app(DeliveryServiceabilityLookupInterface::class);

    $packages = [
        [
            'seller_profile_id' => 1,
            'seller_store_name' => 'Delhi Toys',
            'cod_amount_paise' => 100000, // ₹1,000
            'delivery_pincode' => '110001',
        ],
    ];

    $result = $lookup->validatePackageCodLimits($packages);

    expect($result->isAvailable)->toBeFalse()
        ->and($result->reasonMessage())->toContain("Cash on Delivery is unavailable at pincode 110001 for package from 'Delhi Toys'");
});

test('unserviceable pincode is blocked at checkout with clear reason', function () {
    $this->fakeDriver->serviceabilityMap['999999'] = new ServiceabilityResultDto(
        serviceable: false,
        codAvailable: false,
        maxCodAmountPaise: 0,
        errorMessage: 'Pincode not serviceable by carrier',
    );

    $lookup = app(DeliveryServiceabilityLookupInterface::class);

    $packages = [
        [
            'seller_profile_id' => 1,
            'seller_store_name' => 'Toy Galaxy',
            'cod_amount_paise' => 150000,
            'delivery_pincode' => '999999',
        ],
    ];

    $result = $lookup->validatePackageCodLimits($packages);

    expect($result->isAvailable)->toBeFalse()
        ->and($result->reasonMessage())->toContain("Delivery is unavailable to pincode 999999 for package from 'Toy Galaxy'");
});

test('carrier timeout at checkout does not block the order (fail open)', function () {
    // Override active driver with a mockup that throws a connection timeout
    $carrierManager = app(CarrierManagerInterface::class);
    $mockDriver = new class implements ShippingCarrierInterface
    {
        public function name(): string
        {
            return 'timeout-mock';
        }

        public function checkServiceability(string $pincode, ?string $pickupPincode = null): ServiceabilityResultDto
        {
            throw new ConnectionException('Connection timed out to carrier API (cURL error 28)');
        }

        public function registerPickupAddress(SellerPickupAddressDto $address): SellerWarehouseRegistrationDto
        {
            return new SellerWarehouseRegistrationDto(true, 'WH-1');
        }

        public function getRegisteredAddresses(): array
        {
            return [];
        }

        public function createShipment(CreateShipmentRequestDto $request, SellerOrderShippingDetailsDto $details, SellerOrderInvoiceDto $invoice): CreateShipmentResponseDto
        {
            return new CreateShipmentResponseDto(true, 'AWB-1');
        }

        public function createReverseShipment(CreateShipmentRequestDto $request, SellerOrderShippingDetailsDto $details, SellerOrderInvoiceDto $invoice, string $returnReason, ?Shipment $shipment = null): CreateShipmentResponseDto
        {
            return new CreateShipmentResponseDto(true, 'RVP-1');
        }

        public function downloadLabel(string $awb): string
        {
            return '';
        }

        public function setDispatchDate(array $awbs, string $date): bool
        {
            return true;
        }

        public function cancelShipment(string $awb): bool
        {
            return true;
        }

        public function track(string $awb): TrackingStatusDto
        {
            return new TrackingStatusDto($awb, ShipmentStatus::Created);
        }

        public function requestNdrAction(string $awb, string $action, array $payload = []): array
        {
            return ['success' => true, 'message' => 'mocked'];
        }
    };

    $carrierManager->setDriverInstance('timeout-mock', $mockDriver);
    $carrierManager->setActiveDriver('timeout-mock');

    $lookup = app(DeliveryServiceabilityLookupInterface::class);

    $packages = [
        [
            'seller_profile_id' => 1,
            'seller_store_name' => 'Toy Galaxy',
            'cod_amount_paise' => 200000,
            'delivery_pincode' => '560001',
        ],
    ];

    // Under timeout, the lookup must fail-open rather than throwing or blocking checkout
    $result = $lookup->validatePackageCodLimits($packages);

    expect($result->isAvailable)->toBeTrue()
        ->and($result->failureReasons)->toBeEmpty();
});
