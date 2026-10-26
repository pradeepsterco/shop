<?php

use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use App\Shipping\Models\ShipmentEvent;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->seller = SellerProfile::factory()->create();

    $this->order = Order::create([
        'order_number' => 'KH-'.date('Ymd').'-'.strtoupper(Str::random(6)),
        'user_id' => $this->user->id,
        'checkout_intent_id' => (string) Str::ulid(),
        'status' => OrderStatus::Placed,
        'delivery_address' => [
            'name' => 'Buyer',
            'phone' => '9876543210',
            'address_line' => 'Line 1',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560001',
        ],
        'delivery_state' => 'Karnataka',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Pending,
        'items_mrp_total' => 10000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 10000,
        'placed_at' => now(),
    ]);

    $this->sellerOrder = SellerOrder::create([
        'order_id' => $this->order->id,
        'seller_profile_id' => $this->seller->id,
        'seller_store_name' => 'Store 1',
        'status' => SellerOrderStatus::Shipped,
        'subtotal' => 10000,
        'discount_total' => 0,
        'grand_total' => 10000,
        'placed_at' => now(),
    ]);

    $this->shipment = Shipment::create([
        'seller_order_id' => $this->sellerOrder->id,
        'order_id' => $this->order->id,
        'seller_profile_id' => $this->seller->id,
        'provider' => 'ekart',
        'awb' => '501346BN6838925',
        'vendor_wbn' => '318019134877',
        'status' => ShipmentStatus::InTransit,
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 15,
        'width_cm' => 10,
        'height_cm' => 5,
    ]);
});

test('unconfigured webhook is rejected with 403 Forbidden', function () {
    // Config has null for header and algo
    config([
        'shipping.ekart.webhook_header' => null,
        'shipping.ekart.webhook_algorithm' => null,
        'shipping.ekart.webhook_secret' => null,
    ]);

    $payload = [
        'id' => '501346BN6838925',
        'status' => 'Delivered',
        'ctime' => 1657523187604,
    ];

    $response = $this->postJson(route('shipping.webhooks.ekart'), $payload);

    $response->assertStatus(403)
        ->assertJsonFragment(['message' => 'Webhooks not configured — using polling.']);
});

test('webhook with invalid signature is rejected with 401 Unauthorized', function () {
    config([
        'shipping.ekart.webhook_header' => 'X-Ekart-Signature',
        'shipping.ekart.webhook_algorithm' => 'sha256',
        'shipping.ekart.webhook_secret' => 'super-secret-key',
    ]);

    $payload = [
        'id' => '501346BN6838925',
        'status' => 'Delivered',
        'ctime' => 1657523187604,
    ];

    $response = $this->withHeaders([
        'X-Ekart-Signature' => 'invalid-hmac-signature',
    ])->postJson(route('shipping.webhooks.ekart'), $payload);

    $response->assertStatus(401)
        ->assertJsonFragment(['message' => 'Invalid webhook signature.']);
});

test('valid webhook processes tracking event and marks seller order as Delivered', function () {
    $secret = 'super-secret-key';
    config([
        'shipping.ekart.webhook_header' => 'X-Ekart-Signature',
        'shipping.ekart.webhook_algorithm' => 'sha256',
        'shipping.ekart.webhook_secret' => $secret,
    ]);

    $payload = [
        'id' => '501346BN6838925',
        'status' => 'Delivered',
        'desc' => 'Delivered to Aarav',
        'location' => 'Bengaluru Hub',
        'ctime' => 1657523187604,
    ];

    $json = json_encode($payload);
    $signature = hash_hmac('sha256', $json, $secret);

    $response = $this->call(
        'POST',
        route('shipping.webhooks.ekart'),
        [],
        [],
        [],
        [
            'HTTP_X_EKART_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ],
        $json
    );

    $response->assertOk()
        ->assertJsonFragment(['status' => true]);

    $this->shipment->refresh();
    expect($this->shipment->status)->toBe(ShipmentStatus::Delivered)
        ->and($this->shipment->delivered_at)->not->toBeNull();

    $this->sellerOrder->refresh();
    expect($this->sellerOrder->status)->toBe(SellerOrderStatus::Delivered)
        ->and($this->sellerOrder->delivered_at)->not->toBeNull();
});

test('duplicate webhook creates only one shipment event (idempotency)', function () {
    $secret = 'super-secret-key';
    config([
        'shipping.ekart.webhook_header' => 'X-Ekart-Signature',
        'shipping.ekart.webhook_algorithm' => 'sha256',
        'shipping.ekart.webhook_secret' => $secret,
    ]);

    $payload = [
        'id' => '501346BN6838925',
        'status' => 'In Transit',
        'desc' => 'Arrived at sorting facility',
        'location' => 'Bengaluru Hub',
        'ctime' => 1657523180000,
    ];

    $json = json_encode($payload);
    $signature = hash_hmac('sha256', $json, $secret);

    // First arrival
    $response1 = $this->call(
        'POST',
        route('shipping.webhooks.ekart'),
        [],
        [],
        [],
        ['HTTP_X_EKART_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $json
    );
    $response1->assertOk();
    expect(ShipmentEvent::where('shipment_id', $this->shipment->id)->count())->toBe(1);

    // Duplicate webhook arrival with same payload
    $response2 = $this->call(
        'POST',
        route('shipping.webhooks.ekart'),
        [],
        [],
        [],
        ['HTTP_X_EKART_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
        $json
    );
    $response2->assertOk();

    // Exactly one event is stored; duplicate was ignored idempotently
    expect(ShipmentEvent::where('shipment_id', $this->shipment->id)->count())->toBe(1);
});

test('RTO Delivered webhook marks order RTO but leaves settlements untouched', function () {
    $secret = 'super-secret-key';
    config([
        'shipping.ekart.webhook_header' => 'X-Ekart-Signature',
        'shipping.ekart.webhook_algorithm' => 'sha256',
        'shipping.ekart.webhook_secret' => $secret,
    ]);

    // Initial settlement/ledger state
    $initialSettlementCount = SellerOrderSettlement::count();
    $initialLedgerCount = SellerLedgerEntry::count();

    $payload = [
        'id' => '501346BN6838925',
        'status' => 'RTO Delivered',
        'desc' => 'Returned to origin and delivered to seller',
        'location' => 'Seller Warehouse',
        'ctime' => 1657523190000,
    ];

    $json = json_encode($payload);
    $signature = hash_hmac('sha256', $json, $secret);

    $response = $this->call(
        'POST',
        route('shipping.webhooks.ekart'),
        [],
        [],
        [],
        [
            'HTTP_X_EKART_SIGNATURE' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ],
        $json
    );

    $response->assertOk();

    $this->shipment->refresh();
    expect($this->shipment->status)->toBe(ShipmentStatus::RtoDelivered);

    $this->sellerOrder->refresh();
    expect($this->sellerOrder->status)->toBe(SellerOrderStatus::ReturnedToOrigin);

    // Assert settlements untouched (per requirement 4: RTO Delivered has no settlement change in this phase)
    expect(SellerOrderSettlement::count())->toBe($initialSettlementCount)
        ->and(SellerLedgerEntry::count())->toBe($initialLedgerCount);
});
