<?php

use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Contracts\SellerOrderShippingDetailsLookupInterface;
use App\Orders\DTOs\SellerOrderInvoiceDto;
use App\Orders\DTOs\SellerOrderShippingDetailsDto;
use App\Orders\DTOs\ShippingItemDto;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Shipping\Drivers\EkartCarrierDriver;
use App\Shipping\Drivers\ShiprocketCarrierDriver;
use App\Shipping\DTOs\CreateShipmentRequestDto;
use App\Shipping\Enums\ShipmentDirection;
use App\Shipping\Enums\ShipmentPurpose;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    config(['shipping.ekart.reverse_qc' => false, 'shipping.shiprocket.return_contact_email' => 'returns@example.test']);

    $this->details = new SellerOrderShippingDetailsDto(
        sellerOrderId: 'SO-1', orderId: 'O-1', orderNumber: 'ORD-RVP-1', sellerProfileId: 7, sellerStoreName: 'Toy Hub',
        sellerGstin: null, sellerPhone: '9123456789', sellerPickupAddress1: '5 Warehouse Rd', sellerPickupAddress2: null,
        sellerPickupCity: 'Bengaluru', sellerPickupState: 'Karnataka', sellerPickupPostalCode: '560001',
        customerName: 'Asha Rao', customerPhone: '+91 98765 43210', customerAlternatePhone: null, customerAddress1: '12 MG Road',
        customerAddress2: 'Flat 4', customerCity: 'Pune', customerState: 'Maharashtra', customerPostalCode: '411001',
        isCod: false, codAmountPaise: 0, grandTotalPaise: 10000,
        items: [new ShippingItemDto(orderItemId: 1, listingId: 1, variantId: 1, title: 'Robot', sku: 'SKU-1', quantity: 2, unitPricePaise: 5000, taxRatePercent: 12, taxAmountPaise: 1072, weightGrams: 300, lengthCm: 10, widthCm: 10, heightCm: 10)],
        estimatedTotalWeightGrams: 600,
    );
    $this->invoice = new SellerOrderInvoiceDto('SO-1', 7, 'INV/2627/0001', CarbonImmutable::parse('2026-10-20'), '2627', 1);
    $this->request = new CreateShipmentRequestDto(sellerOrderId: 'SO-1', actualWeightGrams: 600, lengthCm: 20, widthCm: 15, heightCm: 10, pickupLocationAlias: 'WH-7');
});

test('ekart return pickup uses the package create call with payment_mode Pickup and a return_reason', function () {
    Http::fake([
        '*/integrations/v2/auth/token/*' => Http::response(['access_token' => 'ek-token', 'expires_in' => 86400]),
        '*/api/v1/package/create' => Http::response(['tracking_id' => 'EKRVP123', 'barcodes' => ['wbn' => 'WBN1']]),
    ]);

    $driver = new EkartCarrierDriver(['base_url' => 'https://app.elite.ekartlogistics.in', 'client_id' => 'c1', 'username' => 'u', 'password' => 'p', 'safe_mode' => false]);
    $result = $driver->createReverseShipment($this->request, $this->details, $this->invoice, 'Size or fit issue');

    expect($result->success)->toBeTrue()->and($result->awb)->toBe('EKRVP123');

    $body = Http::recorded(fn (Request $r) => str_contains($r->url(), '/api/v1/package/create'))->first()[0]->data();
    expect($body['payment_mode'])->toBe('Pickup')
        ->and($body['return_reason'])->toBe('Size or fit issue')
        ->and($body['cod_amount'])->toBe(0)
        // Per spec: drop_location stays the customer, pickup_location the seller warehouse
        ->and($body['drop_location']['pincode'])->toBe(411001)
        ->and($body['pickup_location'])->toBe(['name' => 'WH-7'])
        ->and($body)->not->toHaveKey('qc_shipment');
});

test('shiprocket return pickup creates a return order then a return AWB, and a retry resumes without a second order', function () {
    Http::fake([
        '*/auth/login' => Http::response(['token' => 'sr-token']),
        '*/orders/create/return' => Http::response(['order_id' => 555, 'shipment_id' => 777]),
        '*/courier/assign/awb' => Http::sequence()
            ->push(['message' => 'no courier'], 422)
            ->push(['response' => ['data' => ['awb_code' => 'SRRVP999', 'courier_name' => 'Delhivery']]]),
    ]);

    $shipment = Shipment::create([
        'order_id' => (string) Str::ulid(), 'seller_order_id' => (string) Str::ulid(), 'seller_profile_id' => 7,
        'direction' => ShipmentDirection::Reverse, 'purpose' => ShipmentPurpose::CustomerReturn, 'provider' => 'shiprocket',
        'status' => ShipmentStatus::Created, 'creation_step' => 'pending', 'fulfillment_attempt' => 2,
        'actual_weight_grams' => 600, 'volumetric_weight_grams' => 600, 'chargeable_weight_grams' => 600, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10,
    ]);
    $driver = new ShiprocketCarrierDriver(['base_url' => 'https://apiv2.shiprocket.in/v1/external', 'email' => 'a@b.c', 'password' => 'x', 'safe_mode' => false]);

    $first = $driver->createReverseShipment($this->request, $this->details, $this->invoice, 'Defective', $shipment);
    expect($first->success)->toBeFalse()
        ->and($shipment->fresh()->creation_step)->toBe('order_created')
        ->and($shipment->fresh()->provider_shipment_id)->toBe('777');

    $retry = $driver->createReverseShipment($this->request, $this->details, $this->invoice, 'Defective', $shipment->fresh());
    expect($retry->success)->toBeTrue()->and($retry->awb)->toBe('SRRVP999');

    $orders = Http::recorded(fn (Request $r) => str_contains($r->url(), '/orders/create/return'));
    $awbs = Http::recorded(fn (Request $r) => str_contains($r->url(), '/courier/assign/awb'));
    expect($orders)->toHaveCount(1)->and($awbs)->toHaveCount(2);

    $order = $orders->first()[0]->data();
    expect($order['order_id'])->toBe('INV-2627-0001-R2')
        ->and($order['pickup_customer_name'])->toBe('Asha Rao')
        ->and($order['pickup_pincode'])->toBe(411001)
        ->and($order['pickup_phone'])->toBe('9876543210')
        ->and($order['pickup_email'])->toBe('returns@example.test')
        ->and($order['shipping_customer_name'])->toBe('Toy Hub')
        ->and($order['shipping_pincode'])->toBe(560001)
        ->and($order['payment_method'])->toBe('PREPAID')
        ->and($order['order_items'][0])->toMatchArray(['sku' => 'SKU-1', 'units' => 2, 'selling_price' => 50.0, 'return_reason' => 'Defective']);

    expect($awbs->last()[0]->data())->toBe(['shipment_id' => 777, 'is_return' => 1]);
});

test('shiprocket return pickup uses the customer account email when the order has one', function () {
    Http::fake([
        '*/auth/login' => Http::response(['token' => 'sr-token']),
        '*/orders/create/return' => Http::response(['order_id' => 1, 'shipment_id' => 2]),
        '*/courier/assign/awb' => Http::response(['response' => ['data' => ['awb_code' => 'SRRVP1']]]),
    ]);

    $withEmail = $this->details->forReship([1 => 1], 0, 5000);
    $withEmail = new SellerOrderShippingDetailsDto(...array_merge(get_object_vars($withEmail), ['customerEmail' => 'asha@example.com']));

    (new ShiprocketCarrierDriver(['base_url' => 'https://apiv2.shiprocket.in/v1/external', 'email' => 'a@b.c', 'password' => 'x', 'safe_mode' => false]))
        ->createReverseShipment($this->request, $withEmail, $this->invoice, 'Defective');

    expect(Http::recorded(fn (Request $r) => str_contains($r->url(), '/orders/create/return'))->first()[0]->data()['pickup_email'])
        ->toBe('asha@example.com');
});

test('the shipping details contract carries the customer account email', function () {
    $customer = User::factory()->create(['email' => 'buyer@example.com']);
    $seller = SellerProfile::factory()->approved()->create(['user_id' => User::factory()->create()->id]);
    $order = Order::create([
        'order_number' => 'ORD-EMAIL-1', 'user_id' => $customer->id, 'checkout_intent_id' => (string) Str::ulid(),
        'status' => OrderStatus::Placed,
        'delivery_address' => ['name' => 'Buyer', 'phone' => '9876543210', 'address_line' => '1 St', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001'],
        'delivery_state' => 'Maharashtra', 'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Pending, 'items_mrp_total' => 0, 'discount_total' => 0, 'delivery_fee' => 0,
        'grand_total' => 0, 'placed_at' => now(),
    ]);
    $sellerOrder = SellerOrder::create([
        'order_id' => $order->id, 'seller_profile_id' => $seller->id, 'seller_store_name' => $seller->store_name,
        'status' => SellerOrderStatus::Placed, 'subtotal' => 0, 'discount_total' => 0, 'grand_total' => 0, 'placed_at' => now(),
    ]);

    $details = app(SellerOrderShippingDetailsLookupInterface::class)->getShippingDetails($sellerOrder->id);

    // A ₹0 package (e.g. a replacement) has nothing to collect, so it is not sent as COD
    expect($details->customerEmail)->toBe('buyer@example.com')
        ->and($details->isCod)->toBeFalse();
});
