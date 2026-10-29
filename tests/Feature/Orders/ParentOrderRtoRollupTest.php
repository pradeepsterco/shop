<?php

use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Contracts\SellerOrderFulfillmentInterface;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->order = Order::create([
        'order_number' => 'ORD-ROLLUP-'.Str::upper(Str::random(5)),
        'user_id' => User::factory()->create()->id,
        'checkout_intent_id' => (string) Str::ulid(),
        'status' => OrderStatus::Placed,
        'delivery_address' => ['name' => 'Buyer', 'phone' => '9876543210', 'address_line' => '1 St', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001'],
        'delivery_state' => 'Maharashtra',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Pending,
        'items_mrp_total' => 20000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 20000,
        'placed_at' => now(),
    ]);

    $makeShipped = function (): SellerOrder {
        $seller = SellerProfile::factory()->approved()->create(['user_id' => User::factory()->create()->id]);

        return SellerOrder::create([
            'order_id' => $this->order->id,
            'seller_profile_id' => $seller->id,
            'seller_store_name' => $seller->store_name,
            'status' => SellerOrderStatus::Shipped,
            'subtotal' => 10000,
            'discount_total' => 0,
            'grand_total' => 10000,
            'placed_at' => now(),
        ]);
    };

    $this->packageA = $makeShipped();
    $this->packageB = $makeShipped();
    $this->fulfillment = app(SellerOrderFulfillmentInterface::class);
});

test('an order whose packages all return to origin is cancelled and its COD payment cancelled', function () {
    $this->fulfillment->markReturnedToOrigin($this->packageA->id);

    // One package still in transit: not closed yet
    expect($this->order->fresh()->status)->toBe(OrderStatus::PartiallyCancelled)
        ->and($this->order->fresh()->payment_status)->toBe(PaymentStatus::Pending);

    $this->fulfillment->markReturnedToOrigin($this->packageB->id);

    $order = $this->order->fresh();
    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->payment_status)->toBe(PaymentStatus::Cancelled);
});

test('mixed delivered and rto packages follow the existing partial rules', function () {
    $this->fulfillment->markDelivered($this->packageA->id);
    $this->fulfillment->markReturnedToOrigin($this->packageB->id);

    $order = $this->order->fresh();
    expect($order->status)->toBe(OrderStatus::PartiallyCancelled)
        ->and($order->payment_status)->toBe(PaymentStatus::Pending);
});

test('an order mixing rto and lost packages is cancelled with COD payment cancelled', function () {
    $this->fulfillment->markReturnedToOrigin($this->packageA->id);
    $this->fulfillment->markLostInTransit($this->packageB->id);

    $order = $this->order->fresh();
    expect($order->status)->toBe(OrderStatus::Cancelled)
        ->and($order->payment_status)->toBe(PaymentStatus::Cancelled);
});
