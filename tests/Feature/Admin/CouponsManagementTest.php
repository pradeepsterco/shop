<?php

use App\Coupons\Enums\CouponFundedBy;
use App\Coupons\Enums\CouponStatus;
use App\Coupons\Enums\DiscountType;
use App\Coupons\Enums\RedemptionStatus;
use App\Coupons\Models\Coupon;
use App\Coupons\Models\CouponRedemption;
use App\Models\Admin;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Orders\Services\CouponOrderReportingService;
use Livewire\Livewire;

test('admin with view permission can access coupons index', function () {
    $admin = Admin::factory()->superAdmin()->create();

    Coupon::create([
        'code' => 'DIWALI50',
        'name' => 'Diwali Dhamaka',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 5000,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.coupons.index')
        ->assertOk()
        ->assertSee('DIWALI50')
        ->assertSee('Diwali Dhamaka');
});

test('admin can create coupon with platform funding', function () {
    $admin = Admin::factory()->superAdmin()->create();

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.coupons.create')
        ->set('code', 'NEWYEAR20')
        ->set('name', 'New Year Offer')
        ->set('discount_type', 'percentage')
        ->set('discount_value', '20')
        ->set('max_discount', '100') // ₹100
        ->set('min_order_amount', '500') // ₹500
        ->set('funded_by', 'platform')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('admin.coupons.index'));

    $coupon = Coupon::where('code', 'NEWYEAR20')->first();
    expect($coupon)->not->toBeNull()
        ->and($coupon->discount_value)->toBe(20)
        ->and($coupon->max_discount)->toBe(10000) // 10,000 paise
        ->and($coupon->min_order_amount)->toBe(50000) // 50,000 paise
        ->and($coupon->funded_by)->toBe(CouponFundedBy::Platform);
});

test('rule 4: creating with seller funding is blocked in admin form with validation message', function () {
    $admin = Admin::factory()->superAdmin()->create();

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.coupons.create')
        ->set('code', 'SELLERPROMO')
        ->set('name', 'Seller Promo')
        ->set('discount_type', 'fixed')
        ->set('discount_value', '50')
        ->set('funded_by', 'seller')
        ->call('save')
        ->assertHasErrors(['funded_by' => 'Seller-funded coupons are not supported in this phase.'])
        ->assertSee('Seller-funded coupons are not supported in this phase.');

    expect(Coupon::where('code', 'SELLERPROMO')->exists())->toBeFalse();
});

test('rule 4: updating with seller funding is blocked in admin edit form with validation message', function () {
    $admin = Admin::factory()->superAdmin()->create();

    $coupon = Coupon::create([
        'code' => 'PLATFORM1',
        'name' => 'Platform 1',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 1000,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.coupons.edit', ['coupon' => $coupon])
        ->set('funded_by', 'seller')
        ->call('update')
        ->assertHasErrors(['funded_by' => 'Seller-funded coupons are not supported in this phase.'])
        ->assertSee('Seller-funded coupons are not supported in this phase.');

    expect($coupon->fresh()->funded_by)->toBe(CouponFundedBy::Platform);
});

test('admin can toggle coupon status between active and paused', function () {
    $admin = Admin::factory()->superAdmin()->create();

    $coupon = Coupon::create([
        'code' => 'TOGGLEME',
        'name' => 'Toggle Me',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 1000,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.coupons.index')
        ->call('toggleStatus', $coupon->id);

    expect($coupon->fresh()->status)->toBe(CouponStatus::Paused);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.coupons.index')
        ->call('toggleStatus', $coupon->id);

    expect($coupon->fresh()->status)->toBe(CouponStatus::Active);
});

test('duplicate coupon generates a unique code and draft status', function () {
    $admin = Admin::factory()->superAdmin()->create();

    $coupon = Coupon::create([
        'code' => 'ORIGINAL',
        'name' => 'Original Coupon',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 15,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.coupons.index')
        ->call('duplicate', $coupon->id);

    $cloned = Coupon::where('name', 'Copy of Original Coupon')->first();
    expect($cloned)->not->toBeNull()
        ->and($cloned->status)->toBe(CouponStatus::Draft)
        ->and($cloned->code)->not->toBe('ORIGINAL')
        ->and($cloned->code)->not->toContain('_COPY') // generated unique code
        ->and(strlen($cloned->code))->toBe(8);
});

test('delete is permitted only if never redeemed and prevented once redeemed', function () {
    $admin = Admin::factory()->superAdmin()->create();

    $coupon = Coupon::create([
        'code' => 'DELETEABLE',
        'name' => 'Delete Me',
        'status' => CouponStatus::Draft,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 1000,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    // 1. Delete succeeds when no redemptions exist
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.coupons.index')
        ->call('deleteCoupon', $coupon->id);

    expect(Coupon::where('code', 'DELETEABLE')->exists())->toBeFalse();

    // 2. Coupon with redemption cannot be deleted
    $redeemedCoupon = Coupon::create([
        'code' => 'REDEEMED',
        'name' => 'Redeemed Coupon',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 1000,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    CouponRedemption::create([
        'coupon_id' => $redeemedCoupon->id,
        'coupon_code' => 'REDEEMED',
        'customer_reference' => '1',
        'order_reference' => 'ORD-1',
        'idempotency_key' => 'idem-1',
        'discount_amount' => 1000,
        'line_allocations' => [],
        'funded_by' => CouponFundedBy::Platform,
        'status' => 'redeemed',
        'redeemed_at' => now(),
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.coupons.index')
        ->call('deleteCoupon', $redeemedCoupon->id);

    expect(Coupon::where('code', 'REDEEMED')->exists())->toBeTrue();
});

test('rule 9: edit component detects historical lock when coupon has redemptions', function () {
    $admin = Admin::factory()->superAdmin()->create();

    $coupon = Coupon::create([
        'code' => 'LOCKED',
        'name' => 'Locked Coupon',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 25,
        'funded_by' => CouponFundedBy::Platform,
    ]);

    CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'coupon_code' => 'LOCKED',
        'customer_reference' => '1',
        'order_reference' => 'ORD-LOCKED',
        'idempotency_key' => 'idem-locked',
        'discount_amount' => 2500,
        'line_allocations' => [],
        'funded_by' => CouponFundedBy::Platform,
        'status' => 'redeemed',
        'redeemed_at' => now(),
    ]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.coupons.edit', ['coupon' => $coupon])
        ->assertOk()
        ->assertSee('Historical Audit Lock Active')
        ->assertSee('LOCKED');
});

test('admin reporting excludes cancelled and RTO orders from total discount given while keeping usage counts and marks consumed non-delivered redemptions', function () {
    $admin = Admin::factory()->superAdmin()->create();

    $coupon = Coupon::create([
        'code' => 'REPORT100',
        'name' => 'Report Test 100',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 10000,
        'usage_limit_total' => 10,
        'usage_limit_per_user' => 5,
        'usage_count' => 2, // 2 active (Order 1 delivered, Order 3 RTO where usage is still consumed)
        'funded_by' => CouponFundedBy::Platform,
    ]);

    $user1 = User::factory()->create();
    $user2 = User::factory()->create();
    $user3 = User::factory()->create();

    // Order 1: Active / Placed (Delivered) with ₹100 discount
    $order1 = Order::create([
        'order_number' => 'KH-ORD-ACTIVE',
        'user_id' => $user1->id,
        'checkout_intent_id' => 'intent-1',
        'status' => OrderStatus::Placed,
        'delivery_address' => [],
        'delivery_state' => 'Karnataka',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Pending,
        'items_mrp_total' => 80000,
        'discount_total' => 0,
        'coupon_code' => 'REPORT100',
        'coupon_discount_total' => 10000,
        'delivery_fee' => 0,
        'grand_total' => 70000,
        'placed_at' => now(),
    ]);
    SellerOrder::create([
        'order_id' => $order1->id,
        'seller_store_name' => 'Store 1',
        'status' => SellerOrderStatus::Placed,
        'subtotal' => 80000,
        'discount_total' => 0,
        'coupon_discount_total' => 10000,
        'grand_total' => 70000,
        'placed_at' => now(),
    ]);
    CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'coupon_code' => $coupon->code,
        'customer_reference' => (string) $user1->id,
        'order_reference' => $order1->order_number,
        'idempotency_key' => 'idemp-act',
        'discount_amount' => 10000,
        'released_amount' => 0,
        'line_allocations' => [1 => 10000],
        'status' => RedemptionStatus::Redeemed,
        'funded_by' => CouponFundedBy::Platform,
        'redeemed_at' => now(),
    ]);

    // Order 2: Cancelled order (released)
    $order2 = Order::create([
        'order_number' => 'KH-ORD-CANCELLED',
        'user_id' => $user2->id,
        'checkout_intent_id' => 'intent-2',
        'status' => OrderStatus::Cancelled,
        'delivery_address' => [],
        'delivery_state' => 'Karnataka',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Cancelled,
        'items_mrp_total' => 80000,
        'discount_total' => 0,
        'coupon_code' => 'REPORT100',
        'coupon_discount_total' => 10000,
        'delivery_fee' => 0,
        'grand_total' => 70000,
        'placed_at' => now(),
    ]);
    SellerOrder::create([
        'order_id' => $order2->id,
        'seller_store_name' => 'Store 1',
        'status' => SellerOrderStatus::Cancelled,
        'subtotal' => 80000,
        'discount_total' => 0,
        'coupon_discount_total' => 10000,
        'grand_total' => 70000,
        'placed_at' => now(),
    ]);
    CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'coupon_code' => $coupon->code,
        'customer_reference' => (string) $user2->id,
        'order_reference' => $order2->order_number,
        'idempotency_key' => 'idemp-can',
        'discount_amount' => 10000,
        'released_amount' => 10000,
        'line_allocations' => [1 => 10000],
        'status' => RedemptionStatus::Released,
        'funded_by' => CouponFundedBy::Platform,
        'redeemed_at' => now(),
    ]);

    // Order 3: Returned to Origin (RTO) where usage is consumed (status = redeemed, restore_usage_on_rto = false)
    $order3 = Order::create([
        'order_number' => 'KH-ORD-RTO',
        'user_id' => $user3->id,
        'checkout_intent_id' => 'intent-3',
        'status' => OrderStatus::Placed,
        'delivery_address' => [],
        'delivery_state' => 'Karnataka',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Pending,
        'items_mrp_total' => 80000,
        'discount_total' => 0,
        'coupon_code' => 'REPORT100',
        'coupon_discount_total' => 10000,
        'delivery_fee' => 0,
        'grand_total' => 70000,
        'placed_at' => now(),
    ]);
    SellerOrder::create([
        'order_id' => $order3->id,
        'seller_store_name' => 'Store 1',
        'status' => SellerOrderStatus::ReturnedToOrigin,
        'subtotal' => 80000,
        'discount_total' => 0,
        'coupon_discount_total' => 10000,
        'grand_total' => 70000,
        'placed_at' => now(),
    ]);
    CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'coupon_code' => $coupon->code,
        'customer_reference' => (string) $user3->id,
        'order_reference' => $order3->order_number,
        'idempotency_key' => 'idemp-rto',
        'discount_amount' => 10000,
        'released_amount' => 0,
        'line_allocations' => [1 => 10000],
        'status' => RedemptionStatus::Redeemed,
        'funded_by' => CouponFundedBy::Platform,
        'redeemed_at' => now(),
    ]);

    // Test CouponOrderReportingService calculation directly
    $reporting = app(CouponOrderReportingService::class);
    $totalDiscountGiven = $reporting->getTotalDiscountGiven($coupon->id);

    // Total discount given must be ₹100 (10,000 paise), EXCLUDING cancelled (Order 2) and RTO (Order 3)
    expect($totalDiscountGiven)->toBe(10000);

    // Test Admin Edit Component
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.coupons.edit', ['coupon' => $coupon])
        ->assertOk()
        // Total discount given displayed as ₹100
        ->assertSee('Total Discount Given')
        ->assertSee('₹100')
        // Active usage count is 2 (Order 1 + Order 3 consumed)
        ->assertSee('Active Usage Count')
        // Order 3 clearly marked as "Consumed — order not delivered"
        ->assertSee('Consumed — order not delivered')
        // Order 2 marked as "Released (Cancelled)"
        ->assertSee('Released (Cancelled)');

    // Test Admin Index Component
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.coupons.index')
        ->assertOk()
        ->assertSee('₹100');
});
