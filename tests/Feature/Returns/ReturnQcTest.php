<?php

use App\Coupons\Enums\CouponFundedBy;
use App\Coupons\Enums\CouponStatus;
use App\Coupons\Enums\DiscountType;
use App\Coupons\Enums\RedemptionStatus;
use App\Coupons\Models\Coupon;
use App\Coupons\Models\CouponRedemption;
use App\Enums\ListingStatus;
use App\Mail\ReturnUpdateMail;
use App\Mail\SellerNewOrderMail;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Orders\Contracts\SellerOrderFulfillmentInterface;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\RefundStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\CustomerRefund;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\SellerOrder;
use App\Returns\Contracts\ReturnRequestServiceInterface;
use App\Returns\Enums\ReturnFault;
use App\Returns\Enums\ReturnReason;
use App\Returns\Enums\ReturnRequestStatus;
use App\Returns\Models\CourierClaim;
use App\Returns\Models\PlatformLoss;
use App\Returns\Models\ReturnRequest;
use App\Settlements\Contracts\PayoutServiceInterface;
use App\Settlements\DTOs\PayoutBatchCreateInput;
use App\Settlements\Enums\LedgerEntryType;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Settlements\Models\SellerPayout;
use App\Shipping\Enums\ShipmentPurpose;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use App\Support\AdminPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    Artisan::call('permissions:sync');
    config([
        'shipping.fake_simulation_enabled' => true,
        'app.display_timezone' => 'Asia/Kolkata',
        'returns.return_report_days' => 3,
        'returns.return_shipping_multiplier' => 1.0,
        'returns.replacement_shipping_multiplier' => 1.0,
        'settlements.minimum_payout_amount_paise' => 100,
    ]);

    $this->deliveredAt = CarbonImmutable::parse('2026-10-20 10:00:00', 'UTC');
    $this->travelTo($this->deliveredAt->addDays(2));

    $this->admin = Admin::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('Super Admin', 'admin'));

    $category = Category::create(['name' => 'Kids']);
    $toys = Vertical::create(['category_id' => $category->id, 'name' => 'Toys', 'hsn_code' => '9503', 'gst_rate' => 12, 'return_window_days' => 10]);
    $this->sellerUser = User::factory()->create();
    $this->sellerProfile = SellerProfile::factory()->approved()->create([
        'user_id' => $this->sellerUser->id, 'bank_account_holder' => 'Toy Hub', 'bank_account_number' => '123456789012',
        'ifsc' => 'HDFC0001234', 'reviewed_at' => now()->subDays(30), 'bank_updated_at' => now()->subDays(40),
    ]);
    $variant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['vertical_id' => $toys->id])->id]);
    $this->listing = Listing::factory()->create(['seller_profile_id' => $this->sellerProfile->id, 'product_variant_id' => $variant->id, 'stock' => 5, 'status' => ListingStatus::Active]);

    $this->customer = User::factory()->create();
    $this->order = Order::create([
        'order_number' => 'ORD-QC-1', 'user_id' => $this->customer->id, 'checkout_intent_id' => (string) Str::ulid(),
        'status' => OrderStatus::Completed,
        'delivery_address' => ['name' => 'Buyer', 'phone' => '9876543210', 'address_line' => '12 MG Road', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001'],
        'delivery_state' => 'Maharashtra', 'payment_method' => PaymentMethod::CashOnDelivery, 'payment_status' => PaymentStatus::Completed,
        'items_mrp_total' => 15000, 'discount_total' => 0, 'coupon_code' => 'SAVE6', 'coupon_discount_total' => 600, 'delivery_fee' => 0,
        'grand_total' => 14400, 'placed_at' => now(),
    ]);
    $this->sellerOrder = SellerOrder::create([
        'order_id' => $this->order->id, 'seller_profile_id' => $this->sellerProfile->id, 'seller_store_name' => $this->sellerProfile->store_name,
        'status' => SellerOrderStatus::Delivered, 'subtotal' => 15000, 'discount_total' => 0, 'coupon_discount_total' => 600,
        'grand_total' => 14400, 'placed_at' => now(), 'delivered_at' => $this->deliveredAt,
    ]);
    // 3 robots at ₹50 with a ₹6 coupon on the line
    $this->toy = OrderItem::create([
        'seller_order_id' => $this->sellerOrder->id, 'listing_id' => $this->listing->id, 'product_variant_id' => $variant->id,
        'sku' => 'SKU-QC', 'upid' => 'UPID-QC', 'seller_name' => $this->sellerProfile->store_name, 'title' => 'Robot',
        'mrp' => 5000, 'qty' => 3, 'unit_price' => 5000, 'line_total' => 15000, 'coupon_discount_amount' => 600,
    ]);

    $this->settlement = SellerOrderSettlement::create([
        'order_id' => $this->order->id, 'seller_order_id' => $this->sellerOrder->id, 'seller_profile_id' => $this->sellerProfile->id,
        'status' => SettlementStatus::OnHold, 'gross_amount' => 15000, 'total_deductions' => 3099, 'net_payout' => 11901,
        'return_window_days' => 10, 'hold_until' => $this->deliveredAt->addDays(10),
    ]);
    foreach ([['sale', LedgerEntryType::Sale, 15000], ['commission', LedgerEntryType::Commission, -1500], ['closing_fee', LedgerEntryType::ClosingFee, -300], ['gst_on_fees', LedgerEntryType::GstOnFees, -324], ['tcs', LedgerEntryType::Tcs, -75], ['shipping_fee', LedgerEntryType::ShippingFee, -900]] as [$key, $type, $amount]) {
        SellerLedgerEntry::create([
            'seller_profile_id' => $this->sellerProfile->id, 'seller_order_id' => $this->sellerOrder->id, 'order_item_id' => $this->toy->id,
            'type' => $type, 'amount' => $amount, 'description' => $type->label(),
            'source_key' => "settlement:{$this->sellerOrder->id}:{$key}:{$this->toy->id}", 'created_at' => now(),
        ]);
    }

    $coupon = Coupon::create(['code' => 'SAVE6', 'name' => 'Save 6', 'status' => CouponStatus::Active, 'discount_type' => DiscountType::Fixed, 'discount_value' => 600, 'funded_by' => CouponFundedBy::Platform, 'usage_count' => 1]);
    $this->redemption = CouponRedemption::create([
        'coupon_id' => $coupon->id, 'coupon_code' => 'SAVE6', 'customer_reference' => (string) $this->customer->id, 'order_reference' => 'ORD-QC-1',
        'idempotency_key' => 'idem-qc-1', 'discount_amount' => 600, 'line_allocations' => [$this->listing->id => 600],
        'funded_by' => CouponFundedBy::Platform, 'status' => RedemptionStatus::Redeemed, 'released_amount' => 0, 'redeemed_at' => now(),
    ]);

    Shipment::create([
        'order_id' => $this->order->id, 'seller_order_id' => $this->sellerOrder->id, 'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake', 'status' => ShipmentStatus::Delivered, 'awb' => 'SIM-QC-FWD', 'creation_step' => 'label_ready', 'delivered_at' => $this->deliveredAt,
        'actual_weight_grams' => 600, 'volumetric_weight_grams' => 600, 'chargeable_weight_grams' => 600, 'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10,
    ]);

    $this->returns = app(ReturnRequestServiceInterface::class);
    $this->simulate = function (string $shipmentId, array $steps) {
        foreach ($steps as $step) {
            Livewire::actingAs($this->admin, 'admin')->test('pages::admin.shipments-show', ['shipment' => $shipmentId])
                ->call('simulateTransition', $step)->assertSee('successfully');
        }
    };
    // Customer requests, admin approves, courier picks up and delivers to the seller
    $this->received = function (int $units, ReturnReason $reason = ReturnReason::SizeFit, string $preference = ReturnRequest::PREFERENCE_REFUND, bool $report = true): ReturnRequest {
        $photos = $reason->requiresPhotos() ? [UploadedFile::fake()->image('proof.jpg')] : [];
        $request = $this->returns->create($this->customer->id, $this->sellerOrder->id, $reason, $preference, [$this->toy->id => $units], $photos);
        $this->returns->approve($request->id, $this->admin->id);
        ($this->simulate)($request->fresh()->reverse_shipment_id, ['picked_up', 'in_transit', 'delivered']);
        if ($report) {
            $this->returns->submitSellerReport($request->id, $this->sellerProfile->id, 'intact', 'Looks unused', [UploadedFile::fake()->image('check.png')]);
        }

        return $request->fresh();
    };
    $this->qc = fn (ReturnRequest $request, bool $passed, ?string $failOutcome = null, ?int $partial = null, ?ReturnFault $fault = null) => $this->returns
        ->decideQc($request->id, $this->admin->id, $passed, $failOutcome, $partial, $fault);
    $this->returnSettlement = fn (ReturnRequest $request) => SellerOrderSettlement::where('kind', SellerOrderSettlement::KIND_RETURN)->where('reference_id', $request->id)->sole();
    $this->entries = fn (string $prefix) => SellerLedgerEntry::where('source_key', 'like', $prefix.'%')->pluck('amount', 'source_key')->all();
});

test('received: the seller gets the return, reports its condition once, and the deadline is shown in India time', function () {
    $request = ($this->received)(1, report: false);

    expect($request->status)->toBe(ReturnRequestStatus::Received)
        ->and($request->report_deadline_at->format('Y-m-d H:i:s'))->toBe(now()->addDays(3)->format('Y-m-d H:i:s'));
    Mail::assertQueued(ReturnUpdateMail::class, fn ($m) => $m->event === 'received' && $m->forSeller && $m->hasTo($this->sellerUser->email));

    $this->actingAs($this->sellerUser, 'web')->get(route('seller.orders.show', ['sellerOrder' => $this->sellerOrder->id]))
        ->assertOk()->assertSee('IST')->assertSee('data-test="submit-return-report-btn"', false);

    $other = SellerProfile::factory()->approved()->create(['user_id' => User::factory()->create()->id]);
    expect(fn () => $this->returns->submitSellerReport($request->id, $other->id, 'intact', null))->toThrow(DomainException::class, 'not for one of your packages');
    expect(fn () => ($this->qc)($request, true))->toThrow(DomainException::class, 'Wait for the seller');

    $this->returns->submitSellerReport($request->id, $this->sellerProfile->id, 'damaged', 'Box crushed', [UploadedFile::fake()->image('a.webp')]);
    Storage::disk('local')->assertExists($request->fresh()->seller_report_photos[0]);
    expect(fn () => $this->returns->submitSellerReport($request->id, $this->sellerProfile->id, 'intact', null))->toThrow(DomainException::class, 'already been submitted');
});

test('a missed report deadline is flagged and the admin decides without it', function () {
    $request = ($this->received)(1, report: false);
    $this->travel(4)->days();

    expect($request->fresh()->sellerReportMissed())->toBeTrue();
    expect(fn () => $this->returns->submitSellerReport($request->id, $this->sellerProfile->id, 'intact', null))->toThrow(DomainException::class, 'deadline has passed');

    Livewire::actingAs($this->admin, 'admin')->test('pages::admin.returns-index')
        ->assertSee('data-test="seller-report-missed"', false)
        ->call('qcPass', $request->id)
        ->assertSee('QC recorded: refund');
});

test('QC pass with refund: per-unit refund after coupon, coupon released, sale and fees reversed for remorse, return shipping charged', function () {
    $request = ($this->received)(2);
    Livewire::actingAs($this->admin, 'admin')->test('pages::admin.returns-index')->call('qcPass', $request->id)->assertSee('QC recorded: refund');

    $refund = CustomerRefund::sole();
    $item = $request->items->first();
    expect($refund->status)->toBe(RefundStatus::Pending)
        // 2 x ₹50 - coupon share floor(600 x 2 / 3) = 10,000 - 400
        ->and($refund->amount_paise)->toBe(9600)
        ->and($this->redemption->fresh()->released_amount)->toBe(400)
        ->and($this->listing->fresh()->stock)->toBe(7)
        ->and(($this->entries)("return:{$item->id}:"))->toBe([
            "return:{$item->id}:sale:{$this->toy->id}" => -10000,
            "return:{$item->id}:commission:{$this->toy->id}" => 1000,
            "return:{$item->id}:closing_fee:{$this->toy->id}" => 200,
            "return:{$item->id}:gst_on_fees:{$this->toy->id}" => 216,
            "return:{$item->id}:tcs:{$this->toy->id}" => 50,
        ])
        ->and(($this->entries)("return:{$request->id}:return_shipping:"))->toBe(["return:{$request->id}:return_shipping:{$this->toy->id}" => -600])
        ->and(($this->returnSettlement)($request)->net_payout)->toBe(-(8534 + 600))
        ->and($this->settlement->fresh()->is_return_hold)->toBeFalse()
        ->and($request->fresh()->status)->toBe(ReturnRequestStatus::Resolved)
        ->and($request->fresh()->qc_outcome)->toBe('refund')
        ->and($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::PartiallyRefunded)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::PartiallyRefunded)
        ->and(AdminActivityLog::where('action', 'returns.qc_decided')->exists())->toBeTrue();

    Mail::assertQueued(ReturnUpdateMail::class, fn ($m) => $m->event === 'qc_passed' && ! $m->forSeller && $m->hasTo($this->customer->email));
    Mail::assertQueued(ReturnUpdateMail::class, fn ($m) => $m->event === 'qc_passed' && $m->forSeller);

    // Decided once only
    expect(fn () => ($this->qc)($request, false, 'full_refund'))->toThrow(DomainException::class, 'already Resolved');
});

test('fees by fault: seller fault keeps fees, courier fault reverses them; the sale and its taxes are always reversed', function () {
    $sellerFault = ($this->received)(1, ReturnReason::Defective);
    ($this->qc)($sellerFault, true);
    $item = $sellerFault->items->first();

    expect(($this->entries)("return:{$item->id}:"))->toBe([
        "return:{$item->id}:sale:{$this->toy->id}" => -5000,
        "return:{$item->id}:tcs:{$this->toy->id}" => 25,
    ])->and($sellerFault->fresh()->qc_fault)->toBe('seller_fault');

    $courier = ($this->received)(1, ReturnReason::Damaged);
    ($this->qc)($courier, true, fault: ReturnFault::CourierFault);
    $courierItem = $courier->items->first();

    expect(($this->entries)("return:{$courierItem->id}:"))->toHaveKey("return:{$courierItem->id}:commission:{$this->toy->id}")
        ->and($courier->fresh()->qc_fault)->toBe('courier_fault');
});

test('QC pass with replacement: ₹0 linked order through the normal flow, stock reserved, return and replacement shipping charged, no refund', function () {
    $request = ($this->received)(1, ReturnReason::Defective, ReturnRequest::PREFERENCE_REPLACEMENT);
    ($this->qc)($request, true);

    $replacement = SellerOrder::with(['items', 'order'])->where('replacement_of_return_request_id', $request->id)->sole();
    expect($request->fresh()->qc_outcome)->toBe('replacement')
        ->and($request->fresh()->replacement_seller_order_id)->toBe($replacement->id)
        ->and($replacement->status)->toBe(SellerOrderStatus::Placed)
        ->and($replacement->grand_total)->toBe(0)
        ->and($replacement->order->delivery_address)->toBe($this->order->delivery_address)
        ->and($replacement->items->sole()->only(['listing_id', 'qty', 'unit_price']))->toBe(['listing_id' => $this->listing->id, 'qty' => 1, 'unit_price' => 0])
        // restocked 1 on pass, reserved 1 for the replacement
        ->and($this->listing->fresh()->stock)->toBe(5)
        ->and(CustomerRefund::count())->toBe(0)
        ->and($this->redemption->fresh()->released_amount)->toBe(0)
        ->and(($this->entries)("return:{$request->items->first()->id}:"))->toBe([])
        ->and(($this->returnSettlement)($request)->net_payout)->toBe(-(300 + 300))
        ->and($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::Delivered);
    Mail::assertQueued(SellerNewOrderMail::class);

    // Shipped through the normal flow -> the customer hears about it
    app(SellerOrderFulfillmentInterface::class)->markShipped($replacement->id, 'fake', 'SIM-REPL-1');
    Mail::assertQueued(ReturnUpdateMail::class, fn ($m) => $m->event === 'replacement_shipped' && $m->hasTo($this->customer->email));

    // A replacement can only be returned for a refund
    $replacement->update(['status' => SellerOrderStatus::Delivered, 'delivered_at' => now()]);
    expect(fn () => $this->returns->create($this->customer->id, $replacement->id, ReturnReason::SizeFit, ReturnRequest::PREFERENCE_REPLACEMENT, [$replacement->items->first()->id => 1]))
        ->toThrow(DomainException::class, 'A replacement can only be returned for a refund.');
});

test('replacement for an inactive listing becomes a refund automatically and the customer is told', function () {
    $request = ($this->received)(1, ReturnReason::Defective, ReturnRequest::PREFERENCE_REPLACEMENT);
    $this->listing->update(['status' => ListingStatus::Inactive]);

    ($this->qc)($request, true);

    expect($request->fresh()->qc_outcome)->toBe('converted_to_refund')
        ->and(SellerOrder::whereNotNull('replacement_of_return_request_id')->count())->toBe(0)
        ->and(CustomerRefund::sole()->amount_paise)->toBe(4800);
    Mail::assertQueued(ReturnUpdateMail::class, fn ($m) => $m->event === 'converted_to_refund' && $m->hasTo($this->customer->email));
});

test('QC fail, ship back: no restock, no refund, sale stands, seller pays return and ship-back shipping', function () {
    $request = ($this->received)(1);
    ($this->qc)($request, false, 'ship_back');

    $shipBack = Shipment::where('purpose', ShipmentPurpose::ShipBack->value)->sole();
    expect($shipBack->booking_status)->toBe(Shipment::BOOKING_BOOKED)
        ->and($shipBack->items->pluck('qty', 'order_item_id')->all())->toBe([(int) $this->toy->id => 1])
        ->and($request->fresh()->ship_back_shipment_id)->toBe($shipBack->id)
        ->and($this->listing->fresh()->stock)->toBe(5)
        ->and(CustomerRefund::count())->toBe(0)
        ->and(($this->returnSettlement)($request)->net_payout)->toBe(-(300 + 300));

    ($this->simulate)($shipBack->id, ['picked_up', 'in_transit', 'out_for_delivery', 'delivered']);
    expect($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::Delivered);
});

test('QC fail with an explicit seller fault: partial refund capped, sale reversed in the same share (seller fault keeps fees)', function () {
    $request = ($this->received)(2);
    expect(fn () => ($this->qc)($request, false, 'partial_refund', 9601, ReturnFault::SellerFault))->toThrow(DomainException::class, 'between ₹0.01 and ₹96.00');

    Livewire::actingAs($this->admin, 'admin')->test('pages::admin.returns-index')
        ->set("qcFailOutcome.{$request->id}", 'partial_refund')
        ->set("qcPartialAmount.{$request->id}", '50')
        ->set("qcFault.{$request->id}", 'seller_fault')
        ->call('qcFail', $request->id)
        ->assertSee('QC recorded: partial refund');

    $item = $request->items->first();
    expect(CustomerRefund::sole()->amount_paise)->toBe(5000)
        ->and($this->listing->fresh()->stock)->toBe(5)
        ->and($request->fresh()->qc_fault)->toBe('seller_fault')
        // share 5,000 / 9,600 of the 2 units' sale and its tax; seller fault keeps the fees
        ->and(($this->entries)("return:{$item->id}:"))->toBe([
            "return:{$item->id}:sale:{$this->toy->id}" => -5208,
            "return:{$item->id}:tcs:{$this->toy->id}" => 26,
        ])
        ->and(($this->returnSettlement)($request)->net_payout)->toBe(-(5182 + 600))
        ->and(PlatformLoss::where('loss_type', PlatformLoss::TYPE_GOODWILL_REFUND)->count())->toBe(0)
        ->and(AdminActivityLog::where('action', 'returns.qc_decided')->sole()->properties['fault_chosen_by_admin'])->toBe('seller_fault');
});

test('QC fail with a refund is platform goodwill by default: seller ledger unchanged, platform loss recorded', function () {
    $request = ($this->received)(1);
    $ledgerBefore = SellerLedgerEntry::where('source_key', 'not like', "return:{$request->id}:return_shipping:%")->pluck('amount', 'source_key')->all();

    ($this->qc)($request, false, 'full_refund');

    $loss = PlatformLoss::where('loss_type', PlatformLoss::TYPE_GOODWILL_REFUND)->sole();
    expect(CustomerRefund::sole()->amount_paise)->toBe(4800)
        ->and($request->fresh()->qc_outcome)->toBe('full_refund')
        ->and($request->fresh()->qc_fault)->toBe('platform_goodwill')
        ->and($this->listing->fresh()->stock)->toBe(5)
        // No sale or fee reversal: only the return pickup shipping (seller pays reverse shipping)
        ->and(SellerLedgerEntry::where('source_key', 'not like', "return:{$request->id}:return_shipping:%")->pluck('amount', 'source_key')->all())->toBe($ledgerBefore)
        ->and(($this->entries)("return:{$request->items->first()->id}:"))->toBe([])
        ->and(($this->returnSettlement)($request)->net_payout)->toBe(-300)
        ->and($loss->loss_amount_paise)->toBe(4800)
        ->and($loss->shipment_id)->toBe($request->reverse_shipment_id);

    // Partial goodwill: the platform absorbs exactly what was refunded
    $second = ($this->received)(1);
    ($this->qc)($second, false, 'partial_refund', 2000);
    expect(PlatformLoss::where('loss_type', PlatformLoss::TYPE_GOODWILL_REFUND)->where('shipment_id', $second->reverse_shipment_id)->sole()->loss_amount_paise)->toBe(2000);
});

test('a seller already paid out carries the return debit into the next payout', function () {
    $this->settlement->update(['status' => SettlementStatus::Paid]);
    $request = ($this->received)(2);
    ($this->qc)($request, true);

    $other = SellerOrder::create([
        'order_id' => $this->order->id, 'seller_profile_id' => $this->sellerProfile->id, 'seller_store_name' => 'x',
        'status' => SellerOrderStatus::Delivered, 'subtotal' => 20000, 'discount_total' => 0, 'grand_total' => 20000, 'placed_at' => now(),
    ]);
    SellerOrderSettlement::create([
        'order_id' => $this->order->id, 'seller_order_id' => $other->id, 'seller_profile_id' => $this->sellerProfile->id,
        'status' => SettlementStatus::Eligible, 'gross_amount' => 20000, 'total_deductions' => 0, 'net_payout' => 20000, 'return_window_days' => 7,
    ]);

    app(PayoutServiceInterface::class)->createWeeklyPayoutBatch(new PayoutBatchCreateInput(scheduledPayoutDate: CarbonImmutable::now()));

    expect(SellerPayout::where('seller_profile_id', $this->sellerProfile->id)->sole()->net_amount)->toBe(20000 - 9134);
});

test('a return the courier loses after pickup: customer refunded in full, seller sale stands, loss and claim recorded', function () {
    $request = $this->returns->create($this->customer->id, $this->sellerOrder->id, ReturnReason::SizeFit, ReturnRequest::PREFERENCE_REFUND, [$this->toy->id => 2]);
    $this->returns->approve($request->id, $this->admin->id);
    $pickupId = $request->fresh()->reverse_shipment_id;

    ($this->simulate)($pickupId, ['picked_up', 'in_transit', 'lost']);

    expect(CustomerRefund::sole()->amount_paise)->toBe(9600)
        ->and($request->fresh()->status)->toBe(ReturnRequestStatus::Resolved)
        ->and($request->fresh()->qc_outcome)->toBe('lost_refund')
        ->and(SellerOrderSettlement::where('kind', SellerOrderSettlement::KIND_RETURN)->count())->toBe(0)
        ->and(SellerLedgerEntry::where('source_key', 'like', 'return:%')->count())->toBe(0)
        ->and(PlatformLoss::where('loss_type', PlatformLoss::TYPE_LOST_RETURN)->sole()->loss_amount_paise)->toBe(9600)
        ->and(CourierClaim::where('shipment_id', $pickupId)->sole()->claim_amount_paise)->toBe(9600)
        ->and($this->redemption->fresh()->released_amount)->toBe(400)
        ->and($this->settlement->fresh()->is_return_hold)->toBeFalse()
        ->and($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::PartiallyRefunded);
    Mail::assertQueued(ReturnUpdateMail::class, fn ($m) => $m->event === 'lost_refund');
});

test('every unit refunded makes the package and the order Refunded', function () {
    ($this->qc)(($this->received)(3), true);

    expect($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::Refunded)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Refunded)
        ->and($this->redemption->fresh()->released_amount)->toBe(600)
        ->and(CustomerRefund::sole()->amount_paise)->toBe(15000 - 600);
});

test('staff without returns.manage cannot decide QC', function (string $method) {
    $request = ($this->received)(1);
    $viewer = Admin::factory()->create();
    $viewer->assignRole(Role::findOrCreate('Returns Viewer', 'admin')->syncPermissions([AdminPermissions::RETURNS_VIEW]));

    Livewire::actingAs($viewer, 'admin')->test('pages::admin.returns-index')
        ->assertDontSee('data-test="qc-pass-btn"', false)
        ->call($method, $request->id)
        ->assertForbidden();

    expect($request->fresh()->status)->toBe(ReturnRequestStatus::Received);
})->with(['qcPass', 'qcFail']);

test('return photos are served to admins with returns.view and to the owning seller only', function () {
    $request = ($this->received)(1, ReturnReason::Damaged);

    $this->actingAs($this->sellerUser, 'web')
        ->get(route('seller.returns.request-photo', ['request' => $request->id, 'kind' => 'customer', 'index' => 0]))->assertOk();
    $this->actingAs($this->sellerUser, 'web')
        ->get(route('seller.returns.request-photo', ['request' => $request->id, 'kind' => 'seller', 'index' => 0]))->assertOk();

    $otherSeller = User::factory()->create();
    SellerProfile::factory()->approved()->create(['user_id' => $otherSeller->id]);
    $this->actingAs($otherSeller, 'web')
        ->get(route('seller.returns.request-photo', ['request' => $request->id, 'kind' => 'customer', 'index' => 0]))->assertForbidden();

    $this->actingAs($this->admin, 'admin')
        ->get(route('admin.returns.request-photo', ['request' => $request->id, 'kind' => 'seller', 'index' => 0]))->assertOk();
});
