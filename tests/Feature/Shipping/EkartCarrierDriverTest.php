<?php

use App\Orders\DTOs\SellerOrderInvoiceDto;
use App\Orders\DTOs\SellerOrderShippingDetailsDto;
use App\Orders\DTOs\ShippingItemDto;
use App\Shipping\Drivers\EkartCarrierDriver;
use App\Shipping\DTOs\CreateShipmentRequestDto;
use App\Shipping\Enums\ShipmentStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->config = [
        'base_url' => 'https://app.elite.ekartlogistics.in',
        'client_id' => 'test-client-123',
        'username' => 'test-user',
        'password' => 'secret-pass',
        'service' => 'SURFACE',
        'safe_mode' => false,
    ];
});

test('authenticates with OAuth endpoint and caches access token', function () {
    Http::fake([
        'https://app.elite.ekartlogistics.in/integrations/v2/auth/token/test-client-123' => Http::response([
            'access_token' => 'sample-bearer-token-xyz',
            'token_type' => 'Bearer',
            'expires_in' => 86400,
            'scope' => 'core:all',
        ], 200),
    ]);

    $driver = new EkartCarrierDriver($this->config);
    $token1 = $driver->getAccessToken();
    $token2 = $driver->getAccessToken();

    expect($token1)->toBe('sample-bearer-token-xyz')
        ->and($token2)->toBe('sample-bearer-token-xyz');

    // Only 1 HTTP call was made due to Cache
    Http::assertSentCount(1);
});

test('refreshes token after cache expiration', function () {
    Http::fake([
        'https://app.elite.ekartlogistics.in/integrations/v2/auth/token/test-client-123' => Http::sequence()
            ->push(['access_token' => 'first-token-111', 'token_type' => 'Bearer', 'expires_in' => 86400], 200)
            ->push(['access_token' => 'refreshed-token-222', 'token_type' => 'Bearer', 'expires_in' => 86400], 200),
    ]);

    $driver = new EkartCarrierDriver($this->config);
    $token1 = $driver->getAccessToken();
    expect($token1)->toBe('first-token-111');

    // Fast forward past cache TTL (20 hours)
    Illuminate\Support\Carbon::setTestNow(now()->addHours(21));

    $token2 = $driver->getAccessToken();
    expect($token2)->toBe('refreshed-token-222');

    // Two calls made because cache expired
    Http::assertSentCount(2);

    Illuminate\Support\Carbon::setTestNow();
});

test('refuses to register webhook when header, algorithm or secret are unconfigured', function () {
    config([
        'shipping.ekart.webhook_header' => null,
        'shipping.ekart.webhook_algorithm' => null,
        'shipping.ekart.webhook_secret' => null,
    ]);

    $driver = new EkartCarrierDriver($this->config);

    expect(fn () => $driver->registerWebhook('https://example.com/webhooks/shipping/ekart'))
        ->toThrow(DomainException::class, 'Cannot register Ekart webhook: Webhook header, algorithm, and secret are not configured. Relying on polling.');
});

test('registers webhook when credentials and header/algorithm are configured', function () {
    config([
        'shipping.ekart.webhook_header' => 'X-Ekart-Signature',
        'shipping.ekart.webhook_algorithm' => 'sha256',
        'shipping.ekart.webhook_secret' => 'super-secret',
    ]);

    Http::fake([
        'https://app.elite.ekartlogistics.in/integrations/v2/auth/token/*' => Http::response(['access_token' => 'sample-token'], 200),
        'https://app.elite.ekartlogistics.in/api/v2/webhook' => Http::response(['status' => true, 'id' => 'wh-123'], 200),
    ]);

    $driver = new EkartCarrierDriver($this->config);
    $registered = $driver->registerWebhook('https://example.com/webhooks/shipping/ekart');

    expect($registered)->toBeTrue();

    Http::assertSent(function ($req) {
        return $req->method() === 'POST'
            && str_contains($req->url(), '/api/v2/webhook')
            && $req['url'] === 'https://example.com/webhooks/shipping/ekart'
            && $req['secret'] === 'super-secret'
            && in_array('track_updated', $req['topics']);
    });
});

test('safe mode blocks shipment creation when active', function () {
    $driver = new EkartCarrierDriver(array_merge($this->config, ['safe_mode' => true]));

    $request = new CreateShipmentRequestDto('so-1', 500, 15, 10, 5);
    $details = new SellerOrderShippingDetailsDto(
        sellerOrderId: 'so-1',
        orderId: 'ord-1',
        orderNumber: 'KH-1',
        sellerProfileId: 1,
        sellerStoreName: 'Store',
        sellerGstin: '29ABCDE1234F1Z5',
        sellerPhone: '9876543210',
        sellerPickupAddress1: 'Pickup Rd',
        sellerPickupAddress2: null,
        sellerPickupCity: 'Bengaluru',
        sellerPickupState: 'Karnataka',
        sellerPickupPostalCode: '560001',
        customerName: 'Customer',
        customerPhone: '9876543210',
        customerAlternatePhone: null,
        customerAddress1: 'Drop Rd',
        customerAddress2: null,
        customerCity: 'Bengaluru',
        customerState: 'Karnataka',
        customerPostalCode: '560002',
        isCod: false,
        codAmountPaise: 0,
        grandTotalPaise: 50000,
        items: [],
        estimatedTotalWeightGrams: 500,
    );
    $invoice = new SellerOrderInvoiceDto('so-1', 1, 'S1/2627/00001', now(), '2627', 1);

    expect(fn () => $driver->createShipment($request, $details, $invoice))
        ->toThrow(DomainException::class, 'Shipping safe mode is active. Live shipment creation is blocked.');
});

test('creates shipment package using HTTP PUT /api/v1/package/create matching spec', function () {
    Http::fake([
        'https://app.elite.ekartlogistics.in/integrations/v2/auth/token/*' => Http::response([
            'access_token' => 'sample-token',
            'token_type' => 'Bearer',
            'expires_in' => 86400,
            'scope' => 'core:all',
        ], 200),
        'https://app.elite.ekartlogistics.in/api/v1/package/create' => Http::response([
            'status' => true,
            'remark' => 'Successfully created shipment',
            'tracking_id' => '500999A3408005',
            'vendor' => 'EKART',
            'barcodes' => [
                'wbn' => 'vendor_waybill_123',
                'order' => 'KH-001',
            ],
        ], 200),
    ]);

    $driver = new EkartCarrierDriver($this->config);

    $items = [
        new ShippingItemDto(
            orderItemId: 1,
            listingId: 1,
            variantId: 1,
            title: 'Wooden Toy Car',
            sku: 'CAR-01',
            quantity: 2,
            unitPricePaise: 10000,
            taxRatePercent: 18,
            taxAmountPaise: 1800,
            weightGrams: 250,
            lengthCm: 15,
            widthCm: 10,
            heightCm: 5,
        ),
    ];

    $request = new CreateShipmentRequestDto('so-1', 500, 15, 10, 5);
    $details = new SellerOrderShippingDetailsDto(
        sellerOrderId: 'so-1',
        orderId: 'ord-1',
        orderNumber: 'KH-001',
        sellerProfileId: 1,
        sellerStoreName: 'Wood Toys',
        sellerGstin: '29ABCDE1234F1Z5',
        sellerPhone: '9876543210',
        sellerPickupAddress1: '12 Industrial Area',
        sellerPickupAddress2: null,
        sellerPickupCity: 'Bengaluru',
        sellerPickupState: 'Karnataka',
        sellerPickupPostalCode: '560001',
        customerName: 'Aarav Patel',
        customerPhone: '9876543210',
        customerAlternatePhone: null,
        customerAddress1: 'Flat 101, Palm Grove',
        customerAddress2: 'Indiranagar',
        customerCity: 'Bengaluru',
        customerState: 'Karnataka',
        customerPostalCode: '560038',
        isCod: true,
        codAmountPaise: 20000,
        grandTotalPaise: 20000,
        items: $items,
        estimatedTotalWeightGrams: 500,
    );
    $invoice = new SellerOrderInvoiceDto('so-1', 1, 'S1/2627/00001', Carbon::parse('2026-10-25'), '2627', 1);

    $response = $driver->createShipment($request, $details, $invoice);

    expect($response->success)->toBeTrue()
        ->and($response->awb)->toBe('500999A3408005')
        ->and($response->vendorWbn)->toBe('vendor_waybill_123')
        ->and($response->publicTrackingUrl)->toBe('https://app.elite.ekartlogistics.in/track/500999A3408005');

    Http::assertSent(function ($req) {
        return $req->method() === 'PUT'
            && str_contains($req->url(), '/api/v1/package/create')
            && $req['invoice_number'] === 'S1/2627/00001'
            && $req['payment_mode'] === 'COD'
            && $req['weight'] === 500;
    });
});

test('updates dispatch date via HTTP POST /data/shipment/dispatch-date with camelCase dispatchDate', function () {
    Http::fake([
        'https://app.elite.ekartlogistics.in/integrations/v2/auth/token/*' => Http::response(['access_token' => 'sample-token', 'token_type' => 'Bearer', 'expires_in' => 86400, 'scope' => 'core:all'], 200),
        'https://app.elite.ekartlogistics.in/data/shipment/dispatch-date' => Http::response(['status' => true, 'remark' => 'Updated'], 200),
    ]);

    $driver = new EkartCarrierDriver($this->config);
    $ok = $driver->setDispatchDate(['500999A3408005'], '2026-10-30');

    expect($ok)->toBeTrue();

    Http::assertSent(function ($req) {
        return $req->method() === 'POST'
            && str_contains($req->url(), '/data/shipment/dispatch-date')
            && $req['dispatchDate'] === '2026-10-30'
            && in_array('500999A3408005', $req['ids']);
    });
});

test('downloads label via HTTP POST /api/v1/package/label?json_only=false', function () {
    Http::fake([
        'https://app.elite.ekartlogistics.in/integrations/v2/auth/token/*' => Http::response(['access_token' => 'sample-token', 'token_type' => 'Bearer', 'expires_in' => 86400, 'scope' => 'core:all'], 200),
        'https://app.elite.ekartlogistics.in/api/v1/package/label*' => Http::response('%PDF-1.4 sample binary label content', 200),
    ]);

    $driver = new EkartCarrierDriver($this->config);
    $binary = $driver->downloadLabel('500999A3408005');

    expect($binary)->toContain('%PDF-1.4');

    Http::assertSent(function ($req) {
        return $req->method() === 'POST'
            && str_contains($req->url(), '/api/v1/package/label?json_only=false')
            && in_array('500999A3408005', $req['ids']);
    });
});

test('cancels shipment via HTTP DELETE /api/v1/package/cancel?tracking_id={awb}', function () {
    Http::fake([
        'https://app.elite.ekartlogistics.in/integrations/v2/auth/token/*' => Http::response(['access_token' => 'sample-token', 'token_type' => 'Bearer', 'expires_in' => 86400, 'scope' => 'core:all'], 200),
        'https://app.elite.ekartlogistics.in/api/v1/package/cancel*' => Http::response(['status' => true], 200),
    ]);

    $driver = new EkartCarrierDriver($this->config);
    $ok = $driver->cancelShipment('500999A3408005');

    expect($ok)->toBeTrue();

    Http::assertSent(function ($req) {
        return $req->method() === 'DELETE'
            && str_contains($req->url(), '/api/v1/package/cancel');
    });
});

test('tracks shipment via GET /api/v1/track/{id} and maps swift statuses including exception', function () {
    Http::fake([
        'https://app.elite.ekartlogistics.in/integrations/v2/auth/token/*' => Http::response(['access_token' => 'sample-token', 'token_type' => 'Bearer', 'expires_in' => 86400, 'scope' => 'core:all'], 200),
        'https://app.elite.ekartlogistics.in/api/v1/track/500999A3408005' => Http::response([
            '_id' => 'track-1',
            'track' => [
                'status' => 'Delivered',
                'desc' => 'Delivered to recipient',
                'location' => 'Bengaluru Indiranagar Hub',
                'ctime' => 1761400000000,
            ],
        ], 200),
    ]);

    $driver = new EkartCarrierDriver($this->config);
    $tracking = $driver->track('500999A3408005');

    expect($tracking->status)->toBe(ShipmentStatus::Delivered)
        ->and($tracking->location)->toBe('Bengaluru Indiranagar Hub');

    // Test exception status mappings per user requirement 3
    expect($driver->mapSwiftStatus('Not Picked'))->toBe(ShipmentStatus::Exception)
        ->and($driver->mapSwiftStatus('Not Serviceable'))->toBe(ShipmentStatus::Exception)
        ->and($driver->mapSwiftStatus('Damaged'))->toBe(ShipmentStatus::Exception)
        ->and($driver->mapSwiftStatus('RTO Delivered'))->toBe(ShipmentStatus::RtoDelivered)
        ->and($driver->mapSwiftStatus('Out for Delivery'))->toBe(ShipmentStatus::OutForDelivery);
});
