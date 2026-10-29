<?php

use App\Coupons\Enums\CouponFundedBy;
use App\Coupons\Enums\CouponStatus;
use App\Coupons\Enums\DiscountType;
use App\Coupons\Enums\RedemptionStatus;
use App\Coupons\Models\Coupon;
use App\Coupons\Models\CouponRedemption;
use App\Mail\RefundStatusMail;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Contracts\CustomerRefundServiceInterface;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\RefundStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\CustomerRefund;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\SellerOrder;
use App\Orders\Services\UnitRefundCalculator;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->customer = User::factory()->create();
    $sellerProfile = SellerProfile::factory()->approved()->create(['user_id' => User::factory()->create()->id]);

    $this->order = Order::create([
        'order_number' => 'ORD-REFUND-1',
        'user_id' => $this->customer->id,
        'checkout_intent_id' => (string) Str::ulid(),
        'status' => OrderStatus::Completed,
        'delivery_address' => ['name' => 'Buyer', 'phone' => '9876543210', 'address_line' => '1 St', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001'],
        'delivery_state' => 'Maharashtra',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Completed,
        'items_mrp_total' => 30000,
        'discount_total' => 0,
        'coupon_code' => 'SAVE10',
        'coupon_discount_total' => 1000,
        'delivery_fee' => 0,
        'grand_total' => 29000,
        'placed_at' => now(),
    ]);
    $this->sellerOrder = SellerOrder::create([
        'order_id' => $this->order->id,
        'seller_profile_id' => $sellerProfile->id,
        'seller_store_name' => $sellerProfile->store_name,
        'status' => SellerOrderStatus::Delivered,
        'subtotal' => 30000,
        'discount_total' => 0,
        'coupon_discount_total' => 1000,
        'grand_total' => 29000,
        'placed_at' => now(),
    ]);
    // 3 units at ₹100 with a ₹10 coupon allocation on the line
    $this->item = OrderItem::create([
        'seller_order_id' => $this->sellerOrder->id,
        'listing_id' => null,
        'product_variant_id' => null,
        'sku' => 'SKU-REF-1',
        'upid' => 'UPID-REF-1',
        'seller_name' => $sellerProfile->store_name,
        'title' => 'Refundable Toy',
        'mrp' => 10000,
        'qty' => 3,
        'unit_price' => 10000,
        'line_total' => 30000,
        'coupon_discount_amount' => 1000,
    ]);

    $coupon = Coupon::create([
        'code' => 'SAVE10',
        'name' => 'Save 10',
        'status' => CouponStatus::Active,
        'discount_type' => DiscountType::Fixed,
        'discount_value' => 1000,
        'funded_by' => CouponFundedBy::Platform,
        'usage_count' => 1,
    ]);
    $this->redemption = CouponRedemption::create([
        'coupon_id' => $coupon->id,
        'coupon_code' => 'SAVE10',
        'customer_reference' => (string) $this->customer->id,
        'order_reference' => 'ORD-REFUND-1',
        'idempotency_key' => 'idem-refund-1',
        'discount_amount' => 1000,
        'line_allocations' => [1 => 1000],
        'funded_by' => CouponFundedBy::Platform,
        'status' => RedemptionStatus::Redeemed,
        'released_amount' => 0,
        'redeemed_at' => now(),
    ]);

    $this->refunds = app(CustomerRefundServiceInterface::class);
    $this->refund = fn (string $sourceId, int $units, bool $paid = true) => $this->refunds
        ->createRefund('return_item', $sourceId, $this->sellerOrder->id, [$this->item->id => $units], $paid);
});

test('unit refunds never split a unit and add up to what the customer paid for the line', function () {
    $first = UnitRefundCalculator::calculate(10000, 3, 1000, unitsPrior: 0, units: 1);
    $second = UnitRefundCalculator::calculate(10000, 3, 1000, unitsPrior: 1, units: 1);
    $third = UnitRefundCalculator::calculate(10000, 3, 1000, unitsPrior: 2, units: 1);

    expect([$first->couponSharePaise, $second->couponSharePaise, $third->couponSharePaise])->toBe([333, 333, 334])
        ->and([$first->amountPaise, $second->amountPaise, $third->amountPaise])->toBe([9667, 9667, 9666])
        ->and($first->amountPaise + $second->amountPaise + $third->amountPaise)->toBe(30000 - 1000);

    // Any split of 7 units sums to line_total - D
    foreach ([[7], [1, 6], [2, 2, 3], [1, 1, 1, 1, 1, 1, 1]] as $parts) {
        $prior = 0;
        $total = 0;
        foreach ($parts as $k) {
            $total += UnitRefundCalculator::calculate(1999, 7, 1001, $prior, $k)->amountPaise;
            $prior += $k;
        }
        expect($total)->toBe(1999 * 7 - 1001);
    }

    expect(fn () => UnitRefundCalculator::calculate(10000, 3, 1000, unitsPrior: 2, units: 2))->toThrow(InvalidArgumentException::class);
});

test('refunds read prior units from stored refunds and release coupon usage with the same rule', function () {
    $one = ($this->refund)('RET-1', 1);
    expect($one->amountPaise)->toBe(9667)
        ->and($one->status)->toBe(RefundStatus::Pending)
        ->and($this->redemption->fresh()->released_amount)->toBe(333)
        ->and($this->redemption->fresh()->status)->toBe(RedemptionStatus::PartiallyReleased);

    $two = ($this->refund)('RET-2', 2);
    expect($two->amountPaise)->toBe(9667 + 9666)
        ->and($this->refunds->refundedUnits($this->item->id))->toBe(3)
        ->and($this->redemption->fresh()->released_amount)->toBe(1000)
        ->and($this->redemption->fresh()->status)->toBe(RedemptionStatus::Released);

    // Coupon share stored per refund item matches what the Coupons module released
    expect((int) DB::table('customer_refund_items')->sum('coupon_share_paise'))->toBe(1000);
});

test('one refund per source and never more units than the line has', function () {
    ($this->refund)('RET-1', 2);

    expect(fn () => ($this->refund)('RET-1', 1))->toThrow(DomainException::class, 'A refund already exists');
    expect(fn () => ($this->refund)('RET-2', 2))->toThrow(DomainException::class, '2 of 3 already refunded');
    expect(fn () => $this->refunds->createRefund('return_item', 'RET-3', $this->sellerOrder->id, [999999 => 1], true))
        ->toThrow(DomainException::class, 'does not belong to this order');

    expect(CustomerRefund::count())->toBe(1);
});

test('units the customer never paid for are recorded as not collected', function () {
    $refund = ($this->refund)('UND-1', 1, paid: false);

    // Nothing is owed in cash; the units' value is still recorded per item and the coupon share released
    expect($refund->status)->toBe(RefundStatus::NotCollected)
        ->and($refund->amountPaise)->toBe(0)
        ->and((int) DB::table('customer_refund_items')->value('amount_paise'))->toBe(9667)
        ->and($this->redemption->fresh()->released_amount)->toBe(333);
});

test('payout details are validated, encrypted at rest and never serialized', function () {
    $refund = ($this->refund)('RET-1', 1);

    foreach ([
        ['payout_method' => 'upi', 'upi_id' => 'no-at-sign'],
        ['payout_method' => 'bank', 'bank_account_holder' => 'Asha Rao', 'bank_account_number' => '12345', 'bank_ifsc' => 'HDFC0001234'],
        ['payout_method' => 'bank', 'bank_account_holder' => 'Asha Rao', 'bank_account_number' => '123456789012', 'bank_ifsc' => 'HDFC1001234'],
        ['payout_method' => 'cash'],
    ] as $invalid) {
        expect(fn () => $this->refunds->updatePayoutDetails($refund->id, $this->customer->id, $invalid))->toThrow(ValidationException::class);
    }

    expect(fn () => $this->refunds->updatePayoutDetails($refund->id, $this->customer->id + 1, ['payout_method' => 'upi', 'upi_id' => 'asha@okhdfc']))
        ->toThrow(DomainException::class, 'does not belong to you');

    // IFSC is normalised to upper case
    $this->refunds->updatePayoutDetails($refund->id, $this->customer->id, [
        'payout_method' => 'bank', 'bank_account_holder' => 'Asha Rao', 'bank_account_number' => '123456789012', 'bank_ifsc' => 'hdfc0001234',
    ]);

    $raw = DB::table('customer_refunds')->where('id', $refund->id)->first();
    $model = CustomerRefund::find($refund->id);

    expect($raw->bank_account_number)->not->toContain('123456789012')
        ->and($raw->bank_ifsc)->not->toContain('HDFC0001234')
        ->and($model->bank_account_number)->toBe('123456789012')
        ->and($model->bank_ifsc)->toBe('HDFC0001234')
        ->and(json_encode($model->toArray()))->not->toContain('123456789012')
        ->and($model->payoutDestination())->toBe('Bank: A/C ****9012, IFSC HDFC*******')
        ->and($model->payoutDestination(full: true))->toContain('123456789012');
});

test('customers hear about paid and failed refunds, refund managers only about failures; a failed refund retries after new details', function () {
    Mail::fake();
    Artisan::call('permissions:sync');

    $manager = Admin::factory()->create(['is_active' => true]);
    $manager->assignRole(Role::findOrCreate('Refund Manager', 'admin')->syncPermissions([AdminPermissions::REFUNDS_MANAGE]));
    $viewer = Admin::factory()->create(['is_active' => true]);
    $viewer->assignRole(Role::findOrCreate('Returns Viewer', 'admin')->syncPermissions([AdminPermissions::RETURNS_VIEW]));

    $refund = ($this->refund)('RET-1', 1);

    expect(fn () => $this->refunds->markPaid($refund->id, 'UTR123456', $manager->id))
        ->toThrow(DomainException::class, 'has not provided UPI or bank details');

    $this->refunds->updatePayoutDetails($refund->id, $this->customer->id, ['payout_method' => 'upi', 'upi_id' => 'asha@okhdfc']);
    $failed = $this->refunds->markFailed($refund->id, 'UPI ID not found', $manager->id);
    expect($failed->status)->toBe(RefundStatus::Failed);

    Mail::assertQueued(RefundStatusMail::class, fn ($mail) => $mail->hasTo($this->customer->email) && ! $mail->forAdmin);
    Mail::assertQueued(RefundStatusMail::class, fn ($mail) => $mail->hasTo($manager->email) && $mail->forAdmin);
    Mail::assertNotQueued(RefundStatusMail::class, fn ($mail) => $mail->hasTo($viewer->email));

    expect(fn () => $this->refunds->markPaid($refund->id, 'UTR123456', $manager->id))->toThrow(DomainException::class, 'Only a pending refund');

    // Customer fixes the details -> back to pending -> paid
    $retry = $this->refunds->updatePayoutDetails($refund->id, $this->customer->id, ['payout_method' => 'upi', 'upi_id' => 'asha.rao@okicici']);
    expect($retry->status)->toBe(RefundStatus::Pending)
        ->and(CustomerRefund::find($refund->id)->failure_reason)->toBeNull();

    $paid = $this->refunds->markPaid($refund->id, 'utr987654321', $manager->id);
    expect($paid->status)->toBe(RefundStatus::Paid)
        ->and(CustomerRefund::find($refund->id)->utr)->toBe('UTR987654321');

    // Paid: customer only. Failed: customer and refund managers.
    Mail::assertQueued(RefundStatusMail::class, 3);
    Mail::assertQueued(RefundStatusMail::class, fn ($mail) => $mail->hasTo($this->customer->email) && $mail->refund->status === RefundStatus::Paid);
    Mail::assertNotQueued(RefundStatusMail::class, fn ($mail) => $mail->hasTo($manager->email) && $mail->refund->status === RefundStatus::Paid);

    expect(fn () => $this->refunds->updatePayoutDetails($refund->id, $this->customer->id, ['payout_method' => 'upi', 'upi_id' => 'x.y@okicici']))
        ->toThrow(DomainException::class, 'only be changed while the refund is pending or failed');
});

test('admin refunds page masks payout details without refunds.manage, and actions are logged without them', function () {
    Mail::fake();
    Artisan::call('permissions:sync');

    $refund = ($this->refund)('RET-1', 1);
    $this->refunds->updatePayoutDetails($refund->id, $this->customer->id, [
        'payout_method' => 'bank', 'bank_account_holder' => 'Asha Rao', 'bank_account_number' => '123456789012', 'bank_ifsc' => 'HDFC0001234',
    ]);

    $viewer = Admin::factory()->create();
    $viewer->assignRole(Role::findOrCreate('Returns Viewer', 'admin')->syncPermissions([AdminPermissions::RETURNS_VIEW]));
    $manager = Admin::factory()->create();
    $manager->assignRole(Role::findOrCreate('Refund Manager', 'admin')->syncPermissions([AdminPermissions::RETURNS_VIEW, AdminPermissions::REFUNDS_MANAGE]));

    $this->actingAs($viewer, 'admin')->get(route('admin.refunds.index'))
        ->assertOk()
        ->assertSee('A/C ****9012')
        ->assertDontSee('123456789012')
        ->assertDontSee('data-test="mark-refund-paid-btn"', false);

    $this->actingAs($manager, 'admin')->get(route('admin.refunds.index'))
        ->assertOk()
        ->assertSee('123456789012')
        ->assertSee('data-test="mark-refund-paid-btn"', false);

    Livewire::actingAs($viewer, 'admin')->test('pages::admin.refunds-index')
        ->set("utr.{$refund->id}", 'UTR123456')
        ->call('markRefundPaid', $refund->id)
        ->assertForbidden();

    Livewire::actingAs($manager, 'admin')->test('pages::admin.refunds-index')
        ->set("utr.{$refund->id}", 'UTR123456')
        ->call('markRefundPaid', $refund->id)
        ->assertSee('Refund marked paid');

    $log = AdminActivityLog::where('action', 'refunds.paid')->sole();
    expect($log->admin_id)->toBe($manager->id)
        ->and(json_encode($log->properties))->toContain('UTR123456')
        ->and(json_encode($log->properties))->not->toContain('123456789012')
        ->and(json_encode($log->properties))->not->toContain('Asha');
});
