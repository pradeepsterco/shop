<?php

use App\Coupons\Enums\CouponFundedBy;
use App\Coupons\Enums\CouponStatus;
use App\Coupons\Enums\DiscountType;
use App\Coupons\Enums\RedemptionStatus;
use App\Coupons\Models\Coupon;
use App\Coupons\Models\CouponRedemption;
use App\Enums\ListingStatus;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\Listing;
use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\RefundStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\CustomerRefund;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\SellerOrder;
use App\Returns\Models\CourierClaim;
use App\Returns\Models\PlatformLoss;
use App\Returns\Models\UndeliveredItem;
use App\Settlements\Enums\LedgerEntryType;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Shipping\Contracts\CarrierManagerInterface;
use App\Shipping\Enums\ShipmentDirection;
use App\Shipping\Enums\ShipmentPurpose;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    Artisan::call('permissions:sync');
    config([
        'shipping.fake_simulation_enabled' => true,
        'returns.reship_shipping_multiplier' => 1.5,
        'returns.rto_shipping_multiplier' => 2.0,
    ]);

    $this->admin = Admin::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('Super Admin', 'admin'));

    $this->sellerProfile = SellerProfile::factory()->approved()->create(['user_id' => User::factory()->create()->id]);
    $this->listing = Listing::factory()->create(['seller_profile_id' => $this->sellerProfile->id, 'stock' => 5, 'status' => ListingStatus::Active]);
    $customer = User::factory()->create();

    $this->order = Order::create([
        'order_number' => 'ORD-UNDEL-1',
        'user_id' => $customer->id,
        'checkout_intent_id' => (string) Str::ulid(),
        'status' => OrderStatus::Placed,
        'delivery_address' => ['name' => 'Buyer', 'phone' => '9876543210', 'address_line' => '12 MG Road', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001'],
        'delivery_state' => 'Maharashtra',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Pending,
        'items_mrp_total' => 20000,
        'discount_total' => 0,
        'coupon_code' => 'SAVE6',
        'coupon_discount_total' => 600,
        'delivery_fee' => 0,
        'grand_total' => 19400,
        'placed_at' => now(),
    ]);
    $this->sellerOrder = SellerOrder::create([
        'order_id' => $this->order->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'seller_store_name' => $this->sellerProfile->store_name,
        'status' => SellerOrderStatus::Shipped,
        'subtotal' => 20000,
        'discount_total' => 0,
        'coupon_discount_total' => 600,
        'grand_total' => 19400,
        'placed_at' => now(),
    ]);

    $line = fn (string $title, int $qty, int $unitPrice, int $coupon) => OrderItem::create([
        'seller_order_id' => $this->sellerOrder->id,
        'listing_id' => $this->listing->id,
        'product_variant_id' => $this->listing->product_variant_id,
        'sku' => 'SKU-'.Str::upper(Str::random(4)),
        'upid' => 'UPID-'.Str::upper(Str::random(4)),
        'seller_name' => $this->sellerProfile->store_name,
        'title' => $title,
        'mrp' => $unitPrice,
        'qty' => $qty,
        'unit_price' => $unitPrice,
        'line_total' => $unitPrice * $qty,
        'coupon_discount_amount' => $coupon,
    ]);
    $this->delivered = $line('Delivered Robot', 1, 10000, 0);
    $this->missing = $line('Missing Puzzle', 2, 5000, 600);

    // Ledger and settlement as written at fee calculation: per line sale 10,000; fees 1,416; forward shipping 800
    $this->settlement = SellerOrderSettlement::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'status' => SettlementStatus::Pending,
        'gross_amount' => 20000,
        'total_deductions' => 4432,
        'net_payout' => 15568,
        'return_window_days' => 7,
    ]);
    foreach ([$this->delivered, $this->missing] as $item) {
        foreach ([['sale', LedgerEntryType::Sale, 10000], ['commission', LedgerEntryType::Commission, -1000], ['closing_fee', LedgerEntryType::ClosingFee, -200], ['gst_on_fees', LedgerEntryType::GstOnFees, -216], ['shipping_fee', LedgerEntryType::ShippingFee, -800]] as [$key, $type, $amount]) {
            SellerLedgerEntry::create([
                'seller_profile_id' => $this->sellerProfile->id,
                'seller_order_id' => $this->sellerOrder->id,
                'order_item_id' => $item->id,
                'type' => $type,
                'amount' => $amount,
                'description' => $type->label(),
                'source_key' => "settlement:{$this->sellerOrder->id}:{$key}:{$item->id}",
                'created_at' => now(),
            ]);
        }
    }

    $coupon = Coupon::create(['code' => 'SAVE6', 'name' => 'Save 6', 'status' => CouponStatus::Active, 'discount_type' => DiscountType::Fixed, 'discount_value' => 600, 'funded_by' => CouponFundedBy::Platform, 'usage_count' => 1]);
    $this->redemption = CouponRedemption::create([
        'coupon_id' => $coupon->id, 'coupon_code' => 'SAVE6', 'customer_reference' => (string) $customer->id, 'order_reference' => 'ORD-UNDEL-1',
        'idempotency_key' => 'idem-undel-1', 'discount_amount' => 600, 'line_allocations' => [$this->listing->id => 600],
        'funded_by' => CouponFundedBy::Platform, 'status' => RedemptionStatus::Redeemed, 'released_amount' => 0, 'redeemed_at' => now(),
    ]);

    $this->original = Shipment::create([
        'order_id' => $this->order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::OutForDelivery,
        'awb' => 'SIM-UNDEL-1',
        'creation_step' => 'label_ready',
        'actual_weight_grams' => 700,
        'volumetric_weight_grams' => 600,
        'chargeable_weight_grams' => 700,
        'length_cm' => 20,
        'width_cm' => 15,
        'height_cm' => 10,
    ]);

    $this->shipmentPage = fn (string $shipmentId) => Livewire::actingAs($this->admin, 'admin')->test('pages::admin.shipments-show', ['shipment' => $shipmentId]);
    $this->queue = fn () => Livewire::actingAs($this->admin, 'admin')->test('pages::admin.returns-index');
    $this->simulate = function (string $shipmentId, array $steps) {
        foreach ($steps as $step) {
            ($this->shipmentPage)($shipmentId)->call('simulateTransition', $step)->assertSee('successfully');
        }
    };

    // Phase A: courier partial delivery, admin confirms only the robot arrived
    ($this->simulate)($this->original->id, ['partial_delivered']);
    ($this->shipmentPage)($this->original->id)
        ->set('selectedDeliveredItems', [(int) $this->delivered->id])
        ->call('submitPartialDelivery')
        ->assertSee('Partial delivery marked');

    $this->item = UndeliveredItem::sole();
    $this->reshipShipment = fn () => Shipment::where('purpose', ShipmentPurpose::Reship->value)->sole();
    $this->reshipSettlement = fn () => SellerOrderSettlement::where('kind', SellerOrderSettlement::KIND_RESHIP)->sole();
    $this->orderLedgerTotal = fn () => (int) SellerLedgerEntry::where('seller_order_id', $this->sellerOrder->id)->sum('amount');
});

test('phase A records the undelivered line with its units', function () {
    expect($this->item->order_item_id)->toBe((int) $this->missing->id)
        ->and($this->item->units)->toBe(2)
        ->and($this->item->line_qty)->toBe(2)
        ->and($this->item->status)->toBe(UndeliveredItem::STATUS_PENDING);
});

test('refund: nothing to pay for COD, coupon released, no ledger change, and reship is then blocked', function () {
    $ledgerBefore = SellerLedgerEntry::count();

    ($this->queue)()->call('refundUndeliveredItem', $this->item->id)->assertSee('Refund recorded');

    $refund = CustomerRefund::sole();
    expect($this->item->fresh()->status)->toBe(UndeliveredItem::STATUS_REFUNDED)
        ->and($this->item->fresh()->refund_id)->toBe($refund->id)
        ->and($refund->status)->toBe(RefundStatus::NotCollected)
        ->and($refund->amount_paise)->toBe(0)
        ->and($refund->items()->sole()->amount_paise)->toBe(10000 - 600)
        ->and($this->redemption->fresh()->released_amount)->toBe(600)
        ->and(SellerLedgerEntry::count())->toBe($ledgerBefore)
        ->and(AdminActivityLog::where('action', 'returns.undelivered_refunded')->where('admin_id', $this->admin->id)->exists())->toBeTrue();

    ($this->queue)()->call('reshipUndeliveredItem', $this->item->id)->assertSee('already been decided (refunded)');
    ($this->queue)()->call('refundUndeliveredItem', $this->item->id)->assertSee('already been decided (refunded)');

    expect(Shipment::where('purpose', ShipmentPurpose::Reship->value)->count())->toBe(0)
        ->and(CustomerRefund::count())->toBe(1)
        ->and($this->listing->fresh()->stock)->toBe(5);
});

test('reship: takes stock once, ships only those units collecting what is owed, and charges the seller reship shipping', function () {
    ($this->queue)()->call('reshipUndeliveredItem', $this->item->id)->assertSee('Reship created');
    // Double submit is safe
    ($this->queue)()->call('reshipUndeliveredItem', $this->item->id)->assertSee('already been decided (reshipping)');
    ($this->queue)()->call('refundUndeliveredItem', $this->item->id)->assertSee('already been decided (reshipping)');

    $reship = ($this->reshipShipment)();
    $settlement = ($this->reshipSettlement)();

    expect($this->listing->fresh()->stock)->toBe(3)
        ->and($reship->direction)->toBe(ShipmentDirection::Forward)
        ->and($reship->parent_shipment_id)->toBe($this->original->id)
        ->and($reship->items->pluck('qty', 'order_item_id')->all())->toBe([(int) $this->missing->id => 2])
        ->and($reship->is_cod)->toBeTrue()
        ->and($reship->cod_amount_paise)->toBe(9400)
        ->and($reship->declared_value_paise)->toBe(9400)
        ->and($reship->awb)->not->toBeEmpty()
        // 800 originally allocated x 1.5
        ->and(SellerLedgerEntry::where('source_key', "reship:{$reship->id}:shipping_fee:{$this->missing->id}")->sole()->amount)->toBe(-1200)
        ->and($settlement->status)->toBe(SettlementStatus::Pending)
        ->and($settlement->net_payout)->toBe(-1200)
        ->and($settlement->reference_id)->toBe($reship->id)
        ->and($this->item->fresh()->status)->toBe(UndeliveredItem::STATUS_RESHIPPING)
        ->and(CustomerRefund::count())->toBe(0)
        ->and(AdminActivityLog::where('action', 'returns.undelivered_reshipped')->count())->toBe(1)
        ->and($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::PartiallyDelivered);

    // The toolbar never offers partial delivery for a reship
    $this->actingAs($this->admin, 'admin')->get(route('admin.shipments.show', ['shipment' => $reship->id]))
        ->assertSee('Forward (Reship)')
        ->assertSee('data-test="simulate-picked_up"', false);
});

test('reship is refused when the listing is out of stock or inactive, and nothing changes', function () {
    $this->listing->update(['stock' => 1]);
    ($this->queue)()->call('reshipUndeliveredItem', $this->item->id)->assertSee('out of stock or its listing is not active; refund the customer instead');

    $this->listing->update(['stock' => 10, 'status' => ListingStatus::Inactive]);
    ($this->queue)()->call('reshipUndeliveredItem', $this->item->id)->assertSee('out of stock or its listing is not active');

    expect($this->listing->fresh()->stock)->toBe(10)
        ->and(Shipment::where('purpose', ShipmentPurpose::Reship->value)->count())->toBe(0)
        ->and(SellerOrderSettlement::where('kind', SellerOrderSettlement::KIND_RESHIP)->count())->toBe(0)
        ->and($this->item->fresh()->status)->toBe(UndeliveredItem::STATUS_PENDING);
});

test('reship delivered: re-credits original sale and fees under reship keys, holds for the saved window from delivery', function () {
    $ledgerAfterPartial = ($this->orderLedgerTotal)();
    $savedWindow = $this->settlement->fresh()->return_window_days;

    ($this->queue)()->call('reshipUndeliveredItem', $this->item->id);
    $reship = ($this->reshipShipment)();

    // Later edits to the vertical's window must not change the hold for this package
    $this->listing->variant->product->vertical->update(['return_window_days' => 30]);

    $this->travel(2)->days();
    ($this->simulate)($reship->id, ['picked_up', 'in_transit', 'out_for_delivery', 'delivered']);

    $credits = SellerLedgerEntry::where('source_key', 'like', "reship:{$reship->id}:%")->pluck('amount', 'source_key')->all();
    expect($credits)->toBe([
        "reship:{$reship->id}:shipping_fee:{$this->missing->id}" => -1200,
        "reship:{$reship->id}:sale:{$this->missing->id}" => 10000,
        "reship:{$reship->id}:commission:{$this->missing->id}" => -1000,
        "reship:{$reship->id}:closing_fee:{$this->missing->id}" => -200,
        "reship:{$reship->id}:gst_on_fees:{$this->missing->id}" => -216,
    ]);

    $settlement = ($this->reshipSettlement)();
    $deliveredAt = $reship->fresh()->delivered_at;

    expect($settlement->status)->toBe(SettlementStatus::OnHold)
        ->and($settlement->gross_amount)->toBe(10000)
        ->and($settlement->net_payout)->toBe(10000 - 1416 - 1200)
        ->and($settlement->return_window_days)->toBe($savedWindow)
        ->and($settlement->hold_until->format('Y-m-d H:i:s'))->toBe($deliveredAt->copy()->addDays($savedWindow)->format('Y-m-d H:i:s'))
        // Ledger for the order: what it was after the partial delivery, plus the reship credits net of reship shipping
        ->and(($this->orderLedgerTotal)())->toBe($ledgerAfterPartial + 10000 - 1416 - 1200)
        ->and($this->item->fresh()->status)->toBe(UndeliveredItem::STATUS_RESHIPPED)
        ->and($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::Delivered)
        ->and($this->sellerOrder->fresh()->expected_cod_amount_paise)->toBe(10000 + 9400)
        ->and($this->settlement->fresh()->kind)->toBe(SellerOrderSettlement::KIND_ORDER);
});

test('reship returned to origin: restocked, reship and RTO shipping charged, item back in the queue', function () {
    $ledgerAfterPartial = ($this->orderLedgerTotal)();

    ($this->queue)()->call('reshipUndeliveredItem', $this->item->id);
    $reship = ($this->reshipShipment)();

    ($this->simulate)($reship->id, ['picked_up', 'in_transit', 'out_for_delivery', 'delivery_failed', 'rto_initiated', 'rto_delivered']);

    $settlement = ($this->reshipSettlement)();
    expect($this->listing->fresh()->stock)->toBe(5)
        ->and(SellerLedgerEntry::where('source_key', "reship:{$reship->id}:rto_shipping")->sole()->amount)->toBe(-2400)
        ->and($settlement->status)->toBe(SettlementStatus::Eligible)
        ->and($settlement->net_payout)->toBe(-1200 - 2400)
        ->and(($this->orderLedgerTotal)())->toBe($ledgerAfterPartial - 1200 - 2400)
        ->and(SellerLedgerEntry::where('source_key', 'like', "reship:{$reship->id}:sale:%")->count())->toBe(0)
        ->and($this->item->fresh()->status)->toBe(UndeliveredItem::STATUS_PENDING)
        ->and($this->item->fresh()->reship_attempts)->toBe(1)
        ->and($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::PartiallyDelivered);

    // Back in the queue: the admin can now refund
    ($this->queue)()->call('refundUndeliveredItem', $this->item->id)->assertSee('Refund recorded');
    expect($this->item->fresh()->status)->toBe(UndeliveredItem::STATUS_REFUNDED);
});

test('reship cancelled before shipping: restocked, reship shipping reversed, item back in the queue', function () {
    ($this->queue)()->call('reshipUndeliveredItem', $this->item->id);
    $reship = ($this->reshipShipment)();

    ($this->shipmentPage)($reship->id)->call('cancelShipment')->assertSee('Shipment cancelled');

    $settlement = ($this->reshipSettlement)();
    expect($this->listing->fresh()->stock)->toBe(5)
        ->and($settlement->status)->toBe(SettlementStatus::Void)
        ->and($settlement->net_payout)->toBe(0)
        ->and((int) SellerLedgerEntry::where('source_key', 'like', "%reship:{$reship->id}:%")->sum('amount'))->toBe(0)
        ->and($this->item->fresh()->status)->toBe(UndeliveredItem::STATUS_PENDING)
        // The partially delivered order is untouched (not reverted to Packed)
        ->and($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::PartiallyDelivered);
});

test('reship lost: seller paid as delivered, customer not charged, claim and platform loss recorded', function () {
    ($this->queue)()->call('reshipUndeliveredItem', $this->item->id);
    $reship = ($this->reshipShipment)();

    ($this->simulate)($reship->id, ['picked_up', 'in_transit', 'lost']);
    ($this->shipmentPage)($reship->id)->call('confirmLostShipment')->assertSee('Shipment confirmed lost');

    $settlement = ($this->reshipSettlement)();
    $refund = CustomerRefund::sole();

    expect($settlement->status)->toBe(SettlementStatus::OnHold)
        ->and($settlement->net_payout)->toBe(10000 - 1416 - 1200)
        ->and($refund->status)->toBe(RefundStatus::NotCollected)
        ->and($refund->source_id)->toBe($this->item->id)
        ->and($this->redemption->fresh()->released_amount)->toBe(600)
        ->and($this->item->fresh()->status)->toBe(UndeliveredItem::STATUS_LOST)
        ->and(CourierClaim::where('shipment_id', $reship->id)->sole()->claim_amount_paise)->toBe(9400)
        ->and(PlatformLoss::where('shipment_id', $reship->id)->sole()->loss_amount_paise)->toBe(10000 - 1416 - 1200)
        ->and($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::PartiallyDelivered)
        // Stock left with the courier: not restocked
        ->and($this->listing->fresh()->stock)->toBe(3);
});

test('staff without returns.manage cannot refund or reship undelivered items', function (string $method) {
    $viewer = Admin::factory()->create();
    $viewer->assignRole(Role::findOrCreate('Returns Viewer', 'admin')->syncPermissions([AdminPermissions::RETURNS_VIEW]));

    Livewire::actingAs($viewer, 'admin')->test('pages::admin.returns-index')
        ->assertDontSee('data-test="reship-undelivered-btn"', false)
        ->call($method, $this->item->id)
        ->assertForbidden();

    expect($this->item->fresh()->status)->toBe(UndeliveredItem::STATUS_PENDING);
})->with(['refundUndeliveredItem', 'reshipUndeliveredItem']);

test('a courier failure leaves a retryable booking_failed reship with stock and charge applied once; retry succeeds', function () {
    $fake = app(CarrierManagerInterface::class)->driver('fake');
    $fake->shouldFailCreation = true;

    ($this->queue)()->call('reshipUndeliveredItem', $this->item->id)->assertSee('the courier booking failed');

    $reship = ($this->reshipShipment)();
    expect($reship->booking_status)->toBe(Shipment::BOOKING_FAILED)
        ->and($reship->booking_error)->toContain('Simulated fake creation failure')
        ->and($reship->awb)->toBeNull()
        ->and($this->listing->fresh()->stock)->toBe(3)
        ->and(($this->reshipSettlement)()->net_payout)->toBe(-1200)
        ->and($this->item->fresh()->status)->toBe(UndeliveredItem::STATUS_RESHIPPING);

    $ledgerCount = SellerLedgerEntry::count();
    $page = fn () => $this->actingAs($this->admin, 'admin')->get(route('admin.shipments.show', ['shipment' => $reship->id]));
    $page()->assertSee('data-test="retry-booking-btn"', false)->assertDontSee('data-test="fake-simulation-toolbar"', false);

    // Still failing: nothing is reserved or charged again
    ($this->shipmentPage)($reship->id)->call('retryBooking')->assertSee('Courier booking failed again');
    expect($reship->fresh()->booking_status)->toBe(Shipment::BOOKING_FAILED)
        ->and($this->listing->fresh()->stock)->toBe(3)
        ->and(SellerLedgerEntry::count())->toBe($ledgerCount);

    $fake->shouldFailCreation = false;
    ($this->shipmentPage)($reship->id)->call('retryBooking')->assertSee('Courier booking succeeded');

    $booked = $reship->fresh();
    expect($booked->booking_status)->toBe(Shipment::BOOKING_BOOKED)
        ->and($booked->awb)->not->toBeEmpty()
        ->and(Shipment::where('purpose', ShipmentPurpose::Reship->value)->count())->toBe(1)
        ->and($this->listing->fresh()->stock)->toBe(3)
        ->and(SellerLedgerEntry::count())->toBe($ledgerCount)
        ->and(($this->reshipSettlement)()->net_payout)->toBe(-1200)
        ->and(AdminActivityLog::where('action', 'shipping.booking_retried')->count())->toBe(2);

    // A booked shipment cannot be booked again
    ($this->shipmentPage)($reship->id)->call('retryBooking')->assertSee('not waiting for a courier booking');
    $page()->assertSee('data-test="fake-simulation-toolbar"', false);
});

test('cancelling a failed reship booking restores stock, reverses the charge and returns the item to the queue', function () {
    app(CarrierManagerInterface::class)->driver('fake')->shouldFailCreation = true;

    ($this->queue)()->call('reshipUndeliveredItem', $this->item->id);
    $reship = ($this->reshipShipment)();

    ($this->shipmentPage)($reship->id)->call('cancelShipment')->assertSee('reship cancelled, stock restored');

    expect($this->listing->fresh()->stock)->toBe(5)
        ->and(($this->reshipSettlement)()->status)->toBe(SettlementStatus::Void)
        ->and(($this->reshipSettlement)()->net_payout)->toBe(0)
        ->and($this->item->fresh()->status)->toBe(UndeliveredItem::STATUS_PENDING)
        ->and($reship->fresh()->status)->toBe(ShipmentStatus::Cancelled);
});
