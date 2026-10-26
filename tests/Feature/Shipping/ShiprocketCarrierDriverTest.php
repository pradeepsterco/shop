<?php

use App\Orders\DTOs\SellerOrderInvoiceDto;
use App\Orders\DTOs\SellerOrderShippingDetailsDto;
use App\Orders\DTOs\ShippingItemDto;
use App\Sellers\DTOs\SellerPickupAddressDto;
use App\Shipping\Drivers\ShiprocketCarrierDriver;
use App\Shipping\DTOs\CreateShipmentRequestDto;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->config = [
        'base_url' => 'https://apiv2.shiprocket.in/v1/external',
        'email' => 'api@khilauna.com',
        'password' => 'secret-password',
        'webhook_token' => 'test-webhook-token-123',
        'courier_selection_rule' => 'recommended',
        'specific_courier_id' => null,
        'safe_mode' => false,
        'timeout_seconds' => 15,
        'retry_attempts' => 2,
    ];
});

test('centralized grams to kg conversion helper converts correctly', function () {
    expect(ShiprocketCarrierDriver::gramsToKg(500))->toBe(0.5)
        ->and(ShiprocketCarrierDriver::gramsToKg(1000))->toBe(1.0)
        ->and(ShiprocketCarrierDriver::gramsToKg(1250))->toBe(1.25)
        ->and(ShiprocketCarrierDriver::gramsToKg(50))->toBe(0.05);
});

test('authenticates with Shiprocket API and caches token for 9 days', function () {
    Http::fake([
        'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response([
            'id' => 12345,
            'token' => 'sr-jwt-bearer-token-xyz',
            'email' => 'api@khilauna.com',
        ], 200),
    ]);

    $driver = new ShiprocketCarrierDriver($this->config);
    $token1 = $driver->getAccessToken();
    $token2 = $driver->getAccessToken();

    expect($token1)->toBe('sr-jwt-bearer-token-xyz')
        ->and($token2)->toBe('sr-jwt-bearer-token-xyz');

    // Only 1 HTTP request made due to 9-day cache
    Http::assertSentCount(1);
});

test('refreshes token after 9 days cache expiration', function () {
    Http::fake([
        'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::sequence()
            ->push(['id' => 12345, 'token' => 'sr-token-initial', 'email' => 'api@khilauna.com'], 200)
            ->push(['id' => 12345, 'token' => 'sr-token-refreshed', 'email' => 'api@khilauna.com'], 200),
    ]);

    $driver = new ShiprocketCarrierDriver($this->config);
    $token1 = $driver->getAccessToken();
    expect($token1)->toBe('sr-token-initial');

    Carbon::setTestNow(now()->addDays(10));

    $token2 = $driver->getAccessToken();
    expect($token2)->toBe('sr-token-refreshed');

    Http::assertSentCount(2);

    Carbon::setTestNow();
});

test('selects courier based on selection strategy', function () {
    $couriers = [
        ['courier_company_id' => 10, 'courier_name' => 'FastAir', 'rate' => 120.0, 'estimated_delivery_days' => 1],
        ['courier_company_id' => 20, 'courier_name' => 'EconomySurface', 'rate' => 45.0, 'estimated_delivery_days' => 5],
        ['courier_company_id' => 30, 'courier_name' => 'StandardPost', 'rate' => 70.0, 'estimated_delivery_days' => 3],
    ];
    $data = [
        'recommended_courier_company_id' => 30,
        'available_courier_companies' => $couriers,
    ];

    // 1. Recommended
    $driverRec = new ShiprocketCarrierDriver(array_merge($this->config, ['courier_selection_rule' => 'recommended']));
    expect($driverRec->selectCourier($couriers, $data)['courier_name'])->toBe('StandardPost');

    // 2. Cheapest
    $driverCheap = new ShiprocketCarrierDriver(array_merge($this->config, ['courier_selection_rule' => 'cheapest']));
    expect($driverCheap->selectCourier($couriers, $data)['courier_name'])->toBe('EconomySurface');

    // 3. Fastest
    $driverFast = new ShiprocketCarrierDriver(array_merge($this->config, ['courier_selection_rule' => 'fastest']));
    expect($driverFast->selectCourier($couriers, $data)['courier_name'])->toBe('FastAir');

    // 4. Specific
    $driverSpec = new ShiprocketCarrierDriver(array_merge($this->config, [
        'courier_selection_rule' => 'specific',
        'specific_courier_id' => 10,
    ]));
    expect($driverSpec->selectCourier($couriers, $data)['courier_name'])->toBe('FastAir');
});

test('blocks live shipment creation when safe mode is active', function () {
    $driver = new ShiprocketCarrierDriver(array_merge($this->config, ['safe_mode' => true]));

    $request = new CreateShipmentRequestDto('so-1', 500, 10, 10, 10);
    $details = new SellerOrderShippingDetailsDto(
        sellerOrderId: 'so-1',
        orderId: 'ord-1',
        orderNumber: 'KH-1',
        sellerProfileId: 1,
        sellerStoreName: 'Toys Store',
        sellerGstin: '29ABCDE1234F1Z5',
        sellerPhone: '9876543210',
        sellerPickupAddress1: '123 Market',
        sellerPickupAddress2: null,
        sellerPickupCity: 'Bengaluru',
        sellerPickupState: 'Karnataka',
        sellerPickupPostalCode: '560001',
        customerName: 'Customer',
        customerPhone: '9876543210',
        customerAlternatePhone: null,
        customerAddress1: '456 Residency',
        customerAddress2: null,
        customerCity: 'Mumbai',
        customerState: 'Maharashtra',
        customerPostalCode: '400001',
        isCod: false,
        codAmountPaise: 0,
        grandTotalPaise: 99900,
        items: [],
        estimatedTotalWeightGrams: 500,
    );
    $invoice = new SellerOrderInvoiceDto('so-1', 1, 'S1/2627/00001', now(), '2627', 1);

    expect(fn () => $driver->createShipment($request, $details, $invoice))
        ->toThrow(DomainException::class, 'Shipping safe mode is active. Live shipment creation is blocked.');
});

test('pre-awb wallet balance check stops before awb assignment if balance is insufficient', function () {
    Http::fake([
        'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token'], 200),
        'https://apiv2.shiprocket.in/v1/external/orders/create/adhoc' => Http::response([
            'order_id' => 999999,
            'shipment_id' => 888888,
            'status' => 'NEW',
            'status_code' => 1,
        ], 200),
        'https://apiv2.shiprocket.in/v1/external/courier/serviceability/*' => Http::response([
            'status' => 200,
            'data' => [
                'available_courier_companies' => [
                    ['courier_company_id' => 12, 'courier_name' => 'Blue Dart', 'rate' => 150.0, 'cod' => 1],
                ],
                'recommended_courier_company_id' => 12,
            ],
        ], 200),
        'https://apiv2.shiprocket.in/v1/external/account/details/wallet-balance' => Http::response([
            'data' => [
                'balance_amount' => '35.50',
            ],
        ], 200),
    ]);

    $driver = new ShiprocketCarrierDriver($this->config);

    $items = [
        new ShippingItemDto(1, 1, 1, 'Toy Robot', 'SKU-ROBOT', 1, 99900, 18, 18000, 500, 10, 10, 10),
    ];
    $request = new CreateShipmentRequestDto('so-1', 500, 10, 10, 10);
    $details = new SellerOrderShippingDetailsDto(
        sellerOrderId: 'so-1',
        orderId: 'ord-1',
        orderNumber: 'KH-1',
        sellerProfileId: 1,
        sellerStoreName: 'Toys Store',
        sellerGstin: '29ABCDE1234F1Z5',
        sellerPhone: '9876543210',
        sellerPickupAddress1: '123 Market',
        sellerPickupAddress2: null,
        sellerPickupCity: 'Bengaluru',
        sellerPickupState: 'Karnataka',
        sellerPickupPostalCode: '560001',
        customerName: 'Customer',
        customerPhone: '9876543210',
        customerAlternatePhone: null,
        customerAddress1: '456 Residency',
        customerAddress2: null,
        customerCity: 'Mumbai',
        customerState: 'Maharashtra',
        customerPostalCode: '400001',
        isCod: false,
        codAmountPaise: 0,
        grandTotalPaise: 99900,
        items: $items,
        estimatedTotalWeightGrams: 500,
    );
    $invoice = new SellerOrderInvoiceDto('so-1', 1, 'S1/2627/00001', now(), '2627', 1);

    expect(fn () => $driver->createShipment($request, $details, $invoice))
        ->toThrow(DomainException::class, 'Shiprocket wallet balance (₹35.50) is below the required courier charge (₹150.00). Please recharge your Shiprocket account wallet.');

    // Assert that AWB assignment was NEVER called
    Http::assertNotSent(function ($request) {
        return str_contains($request->url(), '/courier/assign/awb');
    });
});

test('creates shipment with slash-free order ref and original GST invoice number', function () {
    Storage::fake('local');

    Http::fake([
        'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token'], 200),
        'https://apiv2.shiprocket.in/v1/external/orders/create/adhoc' => Http::response([
            'order_id' => 777777,
            'shipment_id' => 666666,
            'status' => 'NEW',
            'status_code' => 1,
        ], 200),
        'https://apiv2.shiprocket.in/v1/external/courier/serviceability/*' => Http::response([
            'status' => 200,
            'data' => [
                'available_courier_companies' => [
                    ['courier_company_id' => 15, 'courier_name' => 'Delhivery Surface', 'rate' => 60.0, 'cod' => 1],
                ],
                'recommended_courier_company_id' => 15,
            ],
        ], 200),
        'https://apiv2.shiprocket.in/v1/external/account/details/wallet-balance' => Http::response([
            'data' => ['balance_amount' => '500.00'],
        ], 200),
        'https://apiv2.shiprocket.in/v1/external/courier/assign/awb' => Http::response([
            'response' => [
                'data' => [
                    'awb_code' => 'SR-AWB-987654321',
                    'courier_name' => 'Delhivery Surface',
                ],
            ],
        ], 200),
        'https://apiv2.shiprocket.in/v1/external/courier/generate/pickup' => Http::response([
            'pickup_status' => 1,
            'response' => ['data' => 'Pickup scheduled'],
        ], 200),
        'https://apiv2.shiprocket.in/v1/external/courier/generate/label' => Http::response([
            'label_created' => 1,
            'label_url' => 'https://s3.amazonaws.com/shiprocket-test-labels/label-666666.pdf',
        ], 200),
        'https://s3.amazonaws.com/shiprocket-test-labels/label-666666.pdf' => Http::response('%PDF-1.4 Fake PDF Data', 200),
    ]);

    $driver = new ShiprocketCarrierDriver($this->config);

    $items = [
        new ShippingItemDto(1, 1, 1, 'Plush Teddy Bear', 'SKU-TEDDY', 1, 120000, 18, 21600, 500, 15, 12, 10),
    ];
    $request = new CreateShipmentRequestDto('so-1', 500, 15, 12, 10);
    $details = new SellerOrderShippingDetailsDto(
        sellerOrderId: 'so-1',
        orderId: 'ord-1',
        orderNumber: 'KH-1',
        sellerProfileId: 1,
        sellerStoreName: 'Toy World',
        sellerGstin: '29ABCDE1234F1Z5',
        sellerPhone: '9876543210',
        sellerPickupAddress1: 'Shop 4, Market',
        sellerPickupAddress2: null,
        sellerPickupCity: 'Bengaluru',
        sellerPickupState: 'Karnataka',
        sellerPickupPostalCode: '560001',
        customerName: 'Ananya Sharma',
        customerPhone: '9876543210',
        customerAlternatePhone: null,
        customerAddress1: 'Flat 101, Lake View',
        customerAddress2: null,
        customerCity: 'Delhi',
        customerState: 'Delhi',
        customerPostalCode: '110001',
        isCod: false,
        codAmountPaise: 0,
        grandTotalPaise: 120000,
        items: $items,
        estimatedTotalWeightGrams: 500,
    );
    $invoice = new SellerOrderInvoiceDto('so-1', 1, 'S1/2627/00001', now(), '2627', 1);

    $response = $driver->createShipment($request, $details, $invoice);

    expect($response->success)->toBeTrue()
        ->and($response->awb)->toBe('SR-AWB-987654321')
        ->and($response->vendorWbn)->toBe('666666')
        ->and($response->publicTrackingUrl)->toBe('https://shiprocket.co/tracking/SR-AWB-987654321');

    // Verify slash-free order_id and original GST invoice_number
    Http::assertSent(function ($req) {
        if (! str_contains($req->url(), '/orders/create/adhoc')) {
            return false;
        }

        $body = $req->data();

        return $body['order_id'] === 'S1-2627-00001-1'
            && $body['invoice_number'] === 'S1/2627/00001'
            && $body['weight'] === 0.5;
    });

    // Verify label PDF was downloaded immediately
    expect(Storage::disk('local')->exists('shipping_labels/sr_666666.pdf'))->toBeTrue();
});

test('resumes from existing step and never assigns a second awb', function () {
    Storage::fake('local');

    // Create an existing shipment that already has awb_assigned step
    $shipment = Shipment::create([
        'seller_order_id' => 'so-resume',
        'order_id' => 'ord-resume',
        'seller_profile_id' => 1,
        'provider' => 'shiprocket',
        'courier_name' => 'Delhivery Surface',
        'awb' => 'SR-EXISTING-AWB-123',
        'vendor_wbn' => '555555',
        'provider_order_id' => '444444',
        'provider_shipment_id' => '555555',
        'fulfillment_attempt' => 1,
        'creation_step' => 'awb_assigned',
        'status' => ShipmentStatus::Created,
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 300,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
        'is_cod' => false,
        'cod_amount_paise' => 0,
    ]);

    Http::fake([
        'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token'], 200),
        'https://apiv2.shiprocket.in/v1/external/courier/generate/pickup' => Http::response([
            'pickup_status' => 1,
            'response' => ['data' => 'Pickup scheduled'],
        ], 200),
        'https://apiv2.shiprocket.in/v1/external/courier/generate/label' => Http::response([
            'label_created' => 1,
            'label_url' => 'https://s3.amazonaws.com/shiprocket-test-labels/label-555555.pdf',
        ], 200),
        'https://s3.amazonaws.com/shiprocket-test-labels/label-555555.pdf' => Http::response('%PDF-1.4 Fake PDF Data', 200),
    ]);

    $driver = new ShiprocketCarrierDriver($this->config);

    $items = [new ShippingItemDto(1, 1, 1, 'Toy Block', 'SKU-BLOCK', 1, 50000, 18, 9000, 500, 10, 10, 10)];
    $request = new CreateShipmentRequestDto('so-resume', 500, 10, 10, 10);
    $details = new SellerOrderShippingDetailsDto(
        sellerOrderId: 'so-resume',
        orderId: 'ord-resume',
        orderNumber: 'KH-1',
        sellerProfileId: 1,
        sellerStoreName: 'Toy World',
        sellerGstin: '29ABCDE1234F1Z5',
        sellerPhone: '9876543210',
        sellerPickupAddress1: 'Shop 4, Market',
        sellerPickupAddress2: null,
        sellerPickupCity: 'Bengaluru',
        sellerPickupState: 'Karnataka',
        sellerPickupPostalCode: '560001',
        customerName: 'Ananya',
        customerPhone: '9876543210',
        customerAlternatePhone: null,
        customerAddress1: 'Flat 101',
        customerAddress2: null,
        customerCity: 'Delhi',
        customerState: 'Delhi',
        customerPostalCode: '110001',
        isCod: false,
        codAmountPaise: 0,
        grandTotalPaise: 50000,
        items: $items,
        estimatedTotalWeightGrams: 500,
    );
    $invoice = new SellerOrderInvoiceDto('so-resume', 1, 'S1/2627/00001', now(), '2627', 1);

    $response = $driver->createShipment($request, $details, $invoice, $shipment);

    expect($response->success)->toBeTrue()
        ->and($response->awb)->toBe('SR-EXISTING-AWB-123')
        ->and($shipment->fresh()->creation_step)->toBe('label_ready');

    // Never created order or assigned AWB again!
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/orders/create/adhoc'));
    Http::assertNotSent(fn ($req) => str_contains($req->url(), '/courier/assign/awb'));
});

test('immediate local storage of manifest pdf', function () {
    Storage::fake('local');

    Http::fake([
        'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token'], 200),
        'https://apiv2.shiprocket.in/v1/external/manifests/generate' => Http::response([
            'status' => 1,
            'manifest_url' => 'https://s3.amazonaws.com/shiprocket-test-manifests/manifest-666666.pdf',
        ], 200),
        'https://s3.amazonaws.com/shiprocket-test-manifests/manifest-666666.pdf' => Http::response('%PDF-1.4 Fake Manifest', 200),
    ]);

    $driver = new ShiprocketCarrierDriver($this->config);
    $path = $driver->generateManifest('666666');

    expect($path)->toBe('shipping_manifests/sr_666666.pdf')
        ->and(Storage::disk('local')->exists('shipping_manifests/sr_666666.pdf'))->toBeTrue();
});

test('pickup address registration supports pending verification when phone is unverified', function () {
    Http::fake([
        'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token'], 200),
        'https://apiv2.shiprocket.in/v1/external/settings/company/addpickup' => Http::response([
            'success' => true,
            'address' => [
                'pickup_location' => 'WH-1',
                'phone_verified' => 0,
            ],
        ], 200),
    ]);

    $driver = new ShiprocketCarrierDriver($this->config);

    $address = new SellerPickupAddressDto(
        sellerProfileId: 1,
        storeName: 'Toy Store',
        phone: '9876543210',
        email: 'seller@toys.test',
        addressLine1: 'Warehouse 12, Industrial Area',
        addressLine2: null,
        city: 'Bengaluru',
        state: 'Karnataka',
        postalCode: '560001',
        country: 'India',
        gstin: '29ABCDE1234F1Z5',
    );

    $result = $driver->registerPickupAddress($address);

    expect($result->success)->toBeTrue()
        ->and($result->rawResponse['phone_verified'])->toBeFalse()
        ->and($result->rawResponse['status_text'])->toBe('pending_verification');
});

test('status mapping maps codes and labels accurately per Postman collection tables', function () {
    $driver = new ShiprocketCarrierDriver($this->config);

    // Code 26 (Fulfilled) -> Exception (admin review per requirement)
    expect($driver->mapShiprocketStatus(26))->toBe(ShipmentStatus::Exception);

    // Code 23 (Partial_Delivered) -> Exception
    expect($driver->mapShiprocketStatus(23))->toBe(ShipmentStatus::Exception);

    // Code 43 (Self Fulfilled) -> Exception
    expect($driver->mapShiprocketStatus(43))->toBe(ShipmentStatus::Exception);

    // Code 16 (Cancellation Requested) -> null (no status change)
    expect($driver->mapShiprocketStatus(16))->toBeNull();

    // Code 8 & 45 (Cancelled) -> Cancelled
    expect($driver->mapShiprocketStatus(8))->toBe(ShipmentStatus::Cancelled)
        ->and($driver->mapShiprocketStatus(45))->toBe(ShipmentStatus::Cancelled);

    // Code 7 (Delivered) -> Delivered
    expect($driver->mapShiprocketStatus(7))->toBe(ShipmentStatus::Delivered);

    // Code 17 (Out For Delivery) -> OutForDelivery
    expect($driver->mapShiprocketStatus(17))->toBe(ShipmentStatus::OutForDelivery);

    // Code 6 (In Transit), 38 (Reached at Destination) -> InTransit
    expect($driver->mapShiprocketStatus(6))->toBe(ShipmentStatus::InTransit)
        ->and($driver->mapShiprocketStatus(38))->toBe(ShipmentStatus::InTransit);

    // Code 10 (RTO Delivered) -> RtoDelivered
    expect($driver->mapShiprocketStatus(10))->toBe(ShipmentStatus::RtoDelivered);

    // Code 9 (RTO Acknowledged) -> RtoInitiated
    expect($driver->mapShiprocketStatus(9))->toBe(ShipmentStatus::RtoInitiated);

    // Code 12, 44 (Lost) -> Lost
    expect($driver->mapShiprocketStatus(12))->toBe(ShipmentStatus::Lost)
        ->and($driver->mapShiprocketStatus(44))->toBe(ShipmentStatus::Lost);
});
