<?php

use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\Category;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
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
use App\Returns\Enums\ReturnReason;
use App\Returns\Enums\ReturnRequestStatus;
use App\Returns\Models\ReturnRequest;
use App\Returns\Models\UndeliveredItem;
use App\Settlements\Contracts\SettlementServiceInterface;
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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
    Storage::fake('public');
    Artisan::call('permissions:sync');
    config(['shipping.fake_simulation_enabled' => true, 'app.display_timezone' => 'Asia/Kolkata']);

    $this->deliveredAt = CarbonImmutable::parse('2026-10-20 10:00:00', 'UTC');
    $this->travelTo($this->deliveredAt->addDays(2));

    $this->admin = Admin::factory()->create();
    $this->admin->assignRole(Role::findOrCreate('Super Admin', 'admin'));

    $category = Category::create(['name' => 'Kids']);
    $this->toys = Vertical::create(['category_id' => $category->id, 'name' => 'Toys', 'hsn_code' => '9503', 'gst_rate' => 12, 'return_window_days' => 10]);
    $innerwear = Vertical::create(['category_id' => $category->id, 'name' => 'Innerwear', 'hsn_code' => '6108', 'gst_rate' => 5, 'is_returnable' => false]);

    $this->sellerProfile = SellerProfile::factory()->approved()->create(['user_id' => User::factory()->create()->id]);
    $toyVariant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['vertical_id' => $this->toys->id])->id]);
    $sockVariant = ProductVariant::factory()->create(['product_id' => Product::factory()->create(['vertical_id' => $innerwear->id])->id]);
    $listing = Listing::factory()->create(['seller_profile_id' => $this->sellerProfile->id, 'product_variant_id' => $toyVariant->id, 'stock' => 5]);

    $this->customer = User::factory()->create();
    $this->order = Order::create([
        'order_number' => 'ORD-RET-1',
        'user_id' => $this->customer->id,
        'checkout_intent_id' => (string) Str::ulid(),
        'status' => OrderStatus::Completed,
        'delivery_address' => ['name' => 'Buyer', 'phone' => '9876543210', 'address_line' => '12 MG Road', 'city' => 'Pune', 'state' => 'Maharashtra', 'pincode' => '411001'],
        'delivery_state' => 'Maharashtra',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Completed,
        'items_mrp_total' => 17000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 17000,
        'placed_at' => now(),
    ]);
    $this->sellerOrder = SellerOrder::create([
        'order_id' => $this->order->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'seller_store_name' => $this->sellerProfile->store_name,
        'status' => SellerOrderStatus::Delivered,
        'subtotal' => 17000,
        'discount_total' => 0,
        'grand_total' => 17000,
        'placed_at' => now(),
        'delivered_at' => $this->deliveredAt,
    ]);
    $line = fn (string $title, int $qty, int $price, ProductVariant $variant, ?int $listingId) => OrderItem::create([
        'seller_order_id' => $this->sellerOrder->id, 'listing_id' => $listingId, 'product_variant_id' => $variant->id,
        'sku' => 'SKU-'.Str::upper(Str::random(4)), 'upid' => 'UPID-'.Str::upper(Str::random(4)), 'seller_name' => $this->sellerProfile->store_name,
        'title' => $title, 'mrp' => $price, 'qty' => $qty, 'unit_price' => $price, 'line_total' => $price * $qty,
    ]);
    $this->toy = $line('Robot', 3, 5000, $toyVariant, $listing->id);
    $this->sock = $line('Socks', 1, 2000, $sockVariant, null);

    // Delivered and on hold with the window saved at delivery (10 days)
    $this->settlement = SellerOrderSettlement::create([
        'order_id' => $this->order->id, 'seller_order_id' => $this->sellerOrder->id, 'seller_profile_id' => $this->sellerProfile->id,
        'status' => SettlementStatus::OnHold, 'gross_amount' => 17000, 'total_deductions' => 3000, 'net_payout' => 14000,
        'return_window_days' => 10, 'hold_until' => $this->deliveredAt->addDays(10),
    ]);
    foreach ([['sale', LedgerEntryType::Sale, 15000], ['commission', LedgerEntryType::Commission, -1500], ['closing_fee', LedgerEntryType::ClosingFee, -300], ['gst_on_fees', LedgerEntryType::GstOnFees, -324], ['shipping_fee', LedgerEntryType::ShippingFee, -900]] as [$key, $type, $amount]) {
        SellerLedgerEntry::create([
            'seller_profile_id' => $this->sellerProfile->id, 'seller_order_id' => $this->sellerOrder->id, 'order_item_id' => $this->toy->id,
            'type' => $type, 'amount' => $amount, 'description' => $type->label(),
            'source_key' => "settlement:{$this->sellerOrder->id}:{$key}:{$this->toy->id}", 'created_at' => now(),
        ]);
    }

    $this->original = Shipment::create([
        'order_id' => $this->order->id, 'seller_order_id' => $this->sellerOrder->id, 'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake', 'status' => ShipmentStatus::Delivered, 'awb' => 'SIM-RET-FWD', 'creation_step' => 'label_ready',
        'delivered_at' => $this->deliveredAt, 'actual_weight_grams' => 600, 'volumetric_weight_grams' => 600, 'chargeable_weight_grams' => 600,
        'length_cm' => 20, 'width_cm' => 15, 'height_cm' => 10,
    ]);

    $this->returns = app(ReturnRequestServiceInterface::class);
    $this->request = fn (array $units, ReturnReason $reason = ReturnReason::SizeFit, array $photos = [], ?int $override = null, ?int $userId = null) => $this->returns->create(
        userId: $userId ?? $this->customer->id,
        sellerOrderId: $this->sellerOrder->id,
        reason: $reason,
        preference: ReturnRequest::PREFERENCE_REFUND,
        units: $units,
        photos: $photos,
        overrideByAdminId: $override,
        overrideReason: $override ? 'Customer was travelling' : null,
    );
    $this->queue = fn () => Livewire::actingAs($this->admin, 'admin')->test('pages::admin.returns-index');
    $this->simulate = function (string $shipmentId, array $steps) {
        foreach ($steps as $step) {
            Livewire::actingAs($this->admin, 'admin')->test('pages::admin.shipments-show', ['shipment' => $shipmentId])
                ->call('simulateTransition', $step)->assertSee('successfully');
        }
    };
    $this->held = fn () => $this->settlement->fresh()->is_return_hold;
});

test('eligibility uses the window saved at delivery, not the vertical\'s current window', function () {
    $this->toys->update(['return_window_days' => 3]);

    $this->travelTo($this->deliveredAt->addDays(9));
    $lines = $this->returns->returnableLines($this->sellerOrder->id);
    expect($lines[$this->toy->id]['available'])->toBe(3)
        ->and($lines[$this->toy->id]['deadline']->format('Y-m-d H:i:s'))->toBe('2026-10-30 10:00:00');

    $request = ($this->request)([$this->toy->id => 1]);
    expect($request->status)->toBe(ReturnRequestStatus::Requested)
        ->and(($this->held)())->toBeTrue();
});

test('after the window it is blocked unless an admin overrides, which is logged', function () {
    $this->travelTo($this->deliveredAt->addDays(11));

    expect(fn () => ($this->request)([$this->toy->id => 1]))->toThrow(DomainException::class, 'The return window for Robot closed on');

    $request = ($this->request)([$this->toy->id => 1], override: $this->admin->id);
    expect($request->window_override_by_admin_id)->toBe($this->admin->id)
        ->and(AdminActivityLog::where('action', 'returns.window_override')->where('admin_id', $this->admin->id)->sole()->properties['reason'])->toBe('Customer was travelling');

    expect(fn () => $this->returns->create($this->customer->id, $this->sellerOrder->id, ReturnReason::SizeFit, ReturnRequest::PREFERENCE_REFUND, [$this->toy->id => 1], overrideByAdminId: $this->admin->id))
        ->toThrow(DomainException::class, 'Give a reason');
});

test('non-returnable verticals only accept damaged, defective, wrong or missing claims', function () {
    expect(fn () => ($this->request)([$this->sock->id => 1], ReturnReason::ChangedMind))
        ->toThrow(DomainException::class, 'Socks can only be returned if it arrived damaged');

    $request = ($this->request)([$this->sock->id => 1], ReturnReason::Damaged, [UploadedFile::fake()->image('sock.jpg')]);
    expect($request->reason)->toBe(ReturnReason::Damaged);
});

test('claims need 1 to 5 JPG, PNG or WebP photos, stored on the private disk', function (array $photos, bool $ok) {
    if (! $ok) {
        expect(fn () => ($this->request)([$this->toy->id => 1], ReturnReason::Defective, $photos))->toThrow(ValidationException::class);
        expect(ReturnRequest::count())->toBe(0)->and(Storage::disk('local')->allFiles())->toBe([]);

        return;
    }

    $request = ($this->request)([$this->toy->id => 1], ReturnReason::Defective, $photos);
    expect($request->photos)->toHaveCount(count($photos));
    foreach ($request->photos as $path) {
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }
})->with([
    'no photo' => [fn () => [], false],
    'gif' => [fn () => [UploadedFile::fake()->image('a.gif')], false],
    'six' => [fn () => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 6)), false],
    'jpg png webp' => [fn () => [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.png'), UploadedFile::fake()->image('c.webp')], true],
]);

test('quantity guard, one open return per package, and only the owner can request', function () {
    expect(fn () => ($this->request)([$this->toy->id => 4]))->toThrow(DomainException::class, 'Only 3 unit(s) of Robot');
    expect(fn () => ($this->request)([$this->toy->id => 1], userId: User::factory()->create()->id))->toThrow(DomainException::class, 'does not belong to you');

    $first = ($this->request)([$this->toy->id => 2]);
    expect(fn () => ($this->request)([$this->toy->id => 1]))->toThrow(DomainException::class, 'already has an open return');
    expect($this->returns->returnableLines($this->sellerOrder->id)[$this->toy->id]['available'])->toBe(1);

    // Rejected units are available again and the hold is released
    $this->returns->reject($first->id, 'Item shows signs of use in photos', $this->admin->id);
    expect(($this->held)())->toBeFalse()
        ->and($this->returns->returnableLines($this->sellerOrder->id)[$this->toy->id]['available'])->toBe(3);
});

test('admin asks for more information and the customer answers', function () {
    $request = ($this->request)([$this->toy->id => 1]);

    ($this->queue)()->set("infoMessage.{$request->id}", 'Which size did you need?')->call('requestReturnInfo', $request->id)->assertSee('Asked the customer');
    expect($request->fresh()->status)->toBe(ReturnRequestStatus::InfoRequested);

    expect(fn () => $this->returns->respond($request->id, User::factory()->create()->id, 'Hi'))->toThrow(DomainException::class, 'does not belong to you');

    $this->returns->respond($request->id, $this->customer->id, 'I need one size larger');
    expect($request->fresh()->status)->toBe(ReturnRequestStatus::Requested)
        ->and($request->fresh()->customer_response)->toBe('I need one size larger')
        ->and(AdminActivityLog::where('action', 'returns.info_requested')->exists())->toBeTrue();
});

test('approval books a return pickup from the customer and the package stays held', function () {
    $request = ($this->request)([$this->toy->id => 2]);

    ($this->queue)()->call('approveReturn', $request->id)->assertSee('pickup booked (AWB SIM-RVP-');

    $pickup = Shipment::where('purpose', ShipmentPurpose::CustomerReturn->value)->sole();
    expect($request->fresh()->status)->toBe(ReturnRequestStatus::Approved)
        ->and($request->fresh()->reverse_shipment_id)->toBe($pickup->id)
        ->and($pickup->direction)->toBe(ShipmentDirection::Reverse)
        ->and($pickup->parent_shipment_id)->toBe($this->original->id)
        ->and($pickup->items->pluck('qty', 'order_item_id')->all())->toBe([(int) $this->toy->id => 2])
        ->and($pickup->is_cod)->toBeFalse()
        ->and($pickup->booking_status)->toBe(Shipment::BOOKING_BOOKED)
        ->and(AdminActivityLog::where('action', 'returns.request_approved')->exists())->toBeTrue();

    // Open return: no release even after the hold date
    $this->travelTo($this->deliveredAt->addDays(20));
    app(SettlementServiceInterface::class)->releaseMaturedSettlements();
    expect($this->settlement->fresh()->status)->toBe(SettlementStatus::OnHold)->and(($this->held)())->toBeTrue();

    // Picked up and delivered to the seller: received, still held for QC (B6)
    ($this->simulate)($pickup->id, ['picked_up', 'in_transit', 'delivered']);
    expect($request->fresh()->status)->toBe(ReturnRequestStatus::Received)
        ->and(($this->held)())->toBeTrue()
        ->and($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::Delivered);
});

test('a pickup that fails and is cancelled closes the request, releases the hold, and the customer may ask again', function () {
    $request = ($this->request)([$this->toy->id => 1]);
    ($this->queue)()->call('approveReturn', $request->id);
    $pickup = Shipment::where('purpose', ShipmentPurpose::CustomerReturn->value)->sole();

    ($this->simulate)($pickup->id, ['pickup_failed', 'pickup_scheduled', 'pickup_failed', 'cancelled']);

    expect($pickup->fresh()->ndr_attempt_count)->toBe(2)
        ->and($request->fresh()->status)->toBe(ReturnRequestStatus::Closed)
        ->and(($this->held)())->toBeFalse();

    $again = ($this->request)([$this->toy->id => 1]);
    expect($again->status)->toBe(ReturnRequestStatus::Requested);
});

test('a failed pickup booking can be retried, or cancelled which closes the request', function () {
    $fake = app(CarrierManagerInterface::class)->driver('fake');
    $fake->shouldFailCreation = true;

    $request = ($this->request)([$this->toy->id => 1]);
    ($this->queue)()->call('approveReturn', $request->id)->assertSee('pickup booking failed');
    $pickup = Shipment::where('purpose', ShipmentPurpose::CustomerReturn->value)->sole();
    expect($pickup->booking_status)->toBe(Shipment::BOOKING_FAILED);

    $fake->shouldFailCreation = false;
    Livewire::actingAs($this->admin, 'admin')->test('pages::admin.shipments-show', ['shipment' => $pickup->id])
        ->call('retryBooking')->assertSee('Courier booking succeeded');
    expect($pickup->fresh()->booking_status)->toBe(Shipment::BOOKING_BOOKED)
        ->and(Shipment::where('purpose', ShipmentPurpose::CustomerReturn->value)->count())->toBe(1);

    // Second request, booking fails, admin cancels instead
    $this->returns->pickupCancelled($pickup->id, 'test reset');
    $fake->shouldFailCreation = true;
    $second = ($this->request)([$this->toy->id => 1]);
    ($this->queue)()->call('approveReturn', $second->id);
    $secondPickup = Shipment::find($second->fresh()->reverse_shipment_id);

    Livewire::actingAs($this->admin, 'admin')->test('pages::admin.shipments-show', ['shipment' => $secondPickup->id])
        ->call('cancelShipment')->assertSee('return pickup cancelled');
    expect($second->fresh()->status)->toBe(ReturnRequestStatus::Closed)
        ->and(($this->held)())->toBeFalse()
        ->and($this->sellerOrder->fresh()->status)->toBe(SellerOrderStatus::Delivered);
});

test('a missing-item claim skips the pickup: seller debited for those units and the units go to the refund/reship queue', function () {
    $request = ($this->request)([$this->toy->id => 1], ReturnReason::MissingItem, [UploadedFile::fake()->image('box.jpg')]);

    ($this->queue)()->call('approveReturn', $request->id)->assertSee('Missing-item claim approved');

    $claimSettlement = SellerOrderSettlement::where('kind', SellerOrderSettlement::KIND_RETURN)->sole();
    $item = UndeliveredItem::sole();

    // 1 of 3 units: sale 5,000 reversed, fees 500 + 100 + 108 given back => 4,292 debited; shipping stays charged
    expect(Shipment::where('purpose', ShipmentPurpose::CustomerReturn->value)->count())->toBe(0)
        ->and($claimSettlement->reference_id)->toBe($request->id)
        ->and($claimSettlement->status)->toBe(SettlementStatus::Eligible)
        ->and($claimSettlement->net_payout)->toBe(-4292)
        ->and($request->fresh()->status)->toBe(ReturnRequestStatus::Resolved)
        ->and(($this->held)())->toBeFalse()
        ->and($item->origin)->toBe(UndeliveredItem::ORIGIN_MISSING_ITEM_CLAIM)
        ->and($item->customer_paid)->toBeTrue()
        ->and($item->units)->toBe(1)
        ->and($item->line_qty)->toBe(3);

    // The customer paid for it: refunding means real money owed back
    ($this->queue)()->call('refundUndeliveredItem', $item->id)->assertSee('Refund recorded');
    $refund = CustomerRefund::sole();
    expect($refund->status)->toBe(RefundStatus::Pending)
        ->and($refund->amount_paise)->toBe(5000);
});

test('account deletion is blocked by an open return or an unpaid refund', function () {
    ($this->request)([$this->toy->id => 1]);
    $this->sellerOrder->update(['status' => SellerOrderStatus::Delivered]);

    $this->actingAs($this->customer);
    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors(['password']);
    expect($this->customer->fresh())->not->toBeNull();

    // Open refund instead of an open return
    ReturnRequest::query()->update(['status' => ReturnRequestStatus::Resolved->value]);
    CustomerRefund::create([
        'order_id' => $this->order->id, 'seller_order_id' => $this->sellerOrder->id, 'user_id' => $this->customer->id,
        'source_type' => 'return_item', 'source_id' => 'R1', 'amount_paise' => 5000, 'status' => RefundStatus::Pending,
    ]);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors(['password' => 'You can delete your account once your open returns are finished and any refund owed to you has been paid. We are working on them now.']);
});

test('staff without returns.manage cannot decide return requests', function (string $method) {
    $request = ($this->request)([$this->toy->id => 1]);
    $viewer = Admin::factory()->create();
    $viewer->assignRole(Role::findOrCreate('Returns Viewer', 'admin')->syncPermissions([AdminPermissions::RETURNS_VIEW]));

    Livewire::actingAs($viewer, 'admin')->test('pages::admin.returns-index')
        ->assertDontSee('data-test="approve-return-btn"', false)
        ->call($method, $request->id)
        ->assertForbidden();

    expect($request->fresh()->status)->toBe(ReturnRequestStatus::Requested);
})->with(['approveReturn', 'rejectReturn', 'requestReturnInfo']);
