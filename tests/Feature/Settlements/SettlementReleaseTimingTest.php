<?php

use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Settlements\Contracts\SettlementServiceInterface;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerOrderSettlement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('an on-hold settlement is released exactly at hold_until, not 5.5 hours early', function () {
    config(['settlements.timezone' => 'Asia/Kolkata']);

    $sellerProfile = SellerProfile::factory()->approved()->create(['user_id' => User::factory()->create()->id]);
    $order = Order::create([
        'order_number' => 'ORD-RELEASE-TIMING',
        'user_id' => User::factory()->create()->id,
        'checkout_intent_id' => (string) Str::ulid(),
        'status' => OrderStatus::Placed,
        'delivery_address' => ['name' => 'Buyer', 'phone' => '9876543210', 'address_line' => '1 St', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001'],
        'delivery_state' => 'Maharashtra',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Pending,
        'items_mrp_total' => 10000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 10000,
        'placed_at' => now(),
    ]);
    $sellerOrder = SellerOrder::create([
        'order_id' => $order->id,
        'seller_profile_id' => $sellerProfile->id,
        'seller_store_name' => $sellerProfile->store_name,
        'status' => SellerOrderStatus::Delivered,
        'subtotal' => 10000,
        'discount_total' => 0,
        'grand_total' => 10000,
        'placed_at' => now(),
    ]);

    // Delivered 21 Oct 2026 10:00 UTC; 7-day window ends 28 Oct 2026 10:00 UTC (15:30 IST)
    $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'));
    $settlement = SellerOrderSettlement::create([
        'order_id' => $order->id,
        'seller_order_id' => $sellerOrder->id,
        'seller_profile_id' => $sellerProfile->id,
        'status' => SettlementStatus::Pending,
        'gross_amount' => 10000,
        'total_deductions' => 2000,
        'net_payout' => 8000,
        'return_window_days' => 7,
    ]);
    $service = app(SettlementServiceInterface::class);
    $service->markSettlementOnHold($sellerOrder->id);

    expect($settlement->fresh()->hold_until->format('Y-m-d H:i:s'))->toBe('2026-10-28 10:00:00');

    // 5.5 hours early (the old IST comparison released here) and one second early: still on hold
    $this->travelTo(CarbonImmutable::parse('2026-10-28 04:30:00', 'UTC'));
    expect($service->releaseMaturedSettlements())->toBe(0);

    $this->travelTo(CarbonImmutable::parse('2026-10-28 09:59:59', 'UTC'));
    expect($service->releaseMaturedSettlements())->toBe(0)
        ->and($settlement->fresh()->status)->toBe(SettlementStatus::OnHold);

    // Exactly at hold_until: released
    $this->travelTo(CarbonImmutable::parse('2026-10-28 10:00:00', 'UTC'));
    expect($service->releaseMaturedSettlements())->toBe(1)
        ->and($settlement->fresh()->status)->toBe(SettlementStatus::Eligible);
});
