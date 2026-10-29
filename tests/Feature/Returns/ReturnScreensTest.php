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
use App\Returns\Contracts\ReturnRequestServiceInterface;
use App\Returns\Enums\ReturnReason;
use App\Returns\Enums\ReturnRequestStatus;
use App\Returns\Models\ReturnRequest;
use App\Settlements\Enums\LedgerEntryType;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerLedgerEntry;
use App\Settlements\Models\SellerOrderSettlement;
use App\Shipping\Enums\ShipmentDirection;
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

function customerPage(object $test, ?User $user = null)
{
    return Livewire::actingAs($user ?? $test->customer, 'web')->test('pages::storefront.order-show', ['order' => $test->order->order_number]);
}

test('customer sees the return window in India time and requests a return of chosen units from the order page', function () {
    $deadline = $this->deliveredAt->addDays(10);

    customerPage($this)
        ->assertSeeHtml('data-test="return-window"')
        ->assertSee(format_datetime($deadline))
        ->call('startReturn', $this->sellerOrder->id)
        ->assertSeeHtml('data-test="return-form"')
        ->assertSeeHtml('data-test="return-preference"')
        ->set("returnUnits.{$this->toy->id}", 1)
        ->set('returnReason', ReturnReason::SizeFit->value)
        ->call('submitReturn')
        ->assertHasNoErrors()
        ->assertSee('Return requested')
        ->assertSeeHtml('data-test="customer-return-status"');

    $request = ReturnRequest::with('items')->sole();
    expect($request->user_id)->toBe($this->customer->id)
        ->and($request->items->sole()->units)->toBe(1)
        ->and($request->preference)->toBe(ReturnRequest::PREFERENCE_REFUND);
});

test('the return form shows clear errors for missing photos, too many units and an ineligible item', function () {
    $page = customerPage($this)->call('startReturn', $this->sellerOrder->id);

    $page->call('submitReturn')->assertHasErrors('returnReason');

    $page->set("returnUnits.{$this->toy->id}", 1)->set('returnReason', ReturnReason::Damaged->value)
        ->call('submitReturn')->assertHasErrors();
    expect(ReturnRequest::count())->toBe(0);

    $page->set("returnUnits.{$this->toy->id}", 0)->set("returnUnits.{$this->sock->id}", 1)->set('returnReason', ReturnReason::SizeFit->value)
        ->call('submitReturn')->assertHasErrors('returnForm')->assertSee('can only be returned if it arrived damaged');

    $page->set("returnUnits.{$this->sock->id}", 0)->set("returnUnits.{$this->toy->id}", 1)->set('returnReason', ReturnReason::Damaged->value)
        ->set('returnPhotos', [UploadedFile::fake()->image('box.jpg')])
        ->call('submitReturn')->assertHasNoErrors();
    expect(ReturnRequest::sole()->photos)->toHaveCount(1);
});

test('after the window closes the customer sees when it closed and cannot start a return', function () {
    $this->travelTo($this->deliveredAt->addDays(11));

    customerPage($this)
        ->assertSeeHtml('data-test="return-window-closed"')
        ->assertDontSeeHtml('data-test="start-return-btn"')
        ->call('startReturn', $this->sellerOrder->id)
        ->set("returnUnits.{$this->toy->id}", 1)->set('returnReason', ReturnReason::SizeFit->value)
        ->call('submitReturn')
        ->assertHasErrors('returnForm');
    expect(ReturnRequest::count())->toBe(0);
});

test('replacement is not offered on a replacement order', function () {
    $earlier = ($this->request)([$this->toy->id => 1]);
    $this->returns->reject($earlier->id, 'Not needed', $this->admin->id);
    $this->sellerOrder->update(['replacement_of_return_request_id' => $earlier->id]);

    customerPage($this)->call('startReturn', $this->sellerOrder->id)
        ->assertSeeHtml('data-test="return-form"')
        ->assertDontSeeHtml('data-test="return-preference"');
});

test('another customer cannot open the order page or act on its returns', function () {
    $stranger = User::factory()->create();

    $this->actingAs($stranger, 'web')->get(route('orders.show', ['order' => $this->order->order_number]))->assertForbidden();

    $request = ($this->request)([$this->toy->id => 1]);
    $this->returns->requestInfo($request->id, 'Show the label', $this->admin->id);
    expect(fn () => $this->returns->respond($request->id, $stranger->id, 'mine'))->toThrow(DomainException::class);
});

test('customer answers a more-information request with text and photos', function () {
    $request = ($this->request)([$this->toy->id => 1]);
    $this->returns->requestInfo($request->id, 'Please show the label', $this->admin->id);

    customerPage($this)
        ->assertSee('Please show the label')
        ->set("replyText.{$request->id}", 'Here it is')
        ->set('replyPhotos', [UploadedFile::fake()->image('label.png')])
        ->call('submitReply', $request->id)
        ->assertHasNoErrors()
        ->assertSee('we have your answer');

    $request->refresh();
    expect($request->status)->toBe(ReturnRequestStatus::Requested)
        ->and($request->customer_response)->toBe('Here it is')
        ->and($request->photos)->toHaveCount(1);
});

test('return timeline shows the courier\'s own words, place and India time, and the masked UTR once paid', function () {
    $request = ($this->request)([$this->toy->id => 1]);
    $this->returns->approve($request->id, $this->admin->id);
    $pickup = Shipment::where('direction', ShipmentDirection::Reverse)->sole();
    ($this->simulate)($pickup->id, ['picked_up']);
    $event = $pickup->events()->latest('occurred_at')->first();

    customerPage($this)
        ->assertSeeHtml('data-test="return-pickup-timeline"')
        ->assertSee($event->provider_status)
        ->assertSee(format_datetime($event->occurred_at));

    CustomerRefund::create([
        'order_id' => $this->order->id, 'seller_order_id' => $this->sellerOrder->id, 'user_id' => $this->customer->id,
        'source_type' => ReturnRequest::REFUND_SOURCE, 'source_id' => $request->id, 'amount_paise' => 5000,
        'status' => RefundStatus::Paid, 'utr' => 'UTR000123459876', 'paid_at' => now(),
    ]);
    $this->order->update(['status' => OrderStatus::PartiallyRefunded]);

    customerPage($this)
        ->assertSeeHtml('data-test="refund-paid"')
        ->assertSee('••••9876')
        ->assertDontSee('UTR000123459876')
        ->assertSee('Partially Refunded');

    $this->order->update(['status' => OrderStatus::Refunded]);
    customerPage($this)->assertSee('Status: Refunded');
});

test('customer adds UPI or bank details for a refund and updates them after a failed payout', function () {
    $refund = CustomerRefund::create([
        'order_id' => $this->order->id, 'seller_order_id' => $this->sellerOrder->id, 'user_id' => $this->customer->id,
        'source_type' => ReturnRequest::REFUND_SOURCE, 'source_id' => (string) Str::ulid(), 'amount_paise' => 5000,
        'status' => RefundStatus::Pending,
    ]);

    customerPage($this)
        ->assertSee('Add UPI or bank details')
        ->call('editPayout', $refund->id)
        ->set('payoutMethod', 'bank')->set('bankHolder', 'Buyer')->set('bankAccount', '123456789012')->set('bankIfsc', 'BAD')
        ->call('savePayout')
        ->assertHasErrors('payout')
        ->set('bankIfsc', 'HDFC0001234')
        ->call('savePayout')
        ->assertHasNoErrors();
    expect($refund->fresh()->bank_ifsc)->toBe('HDFC0001234');

    $refund->update(['status' => RefundStatus::Failed, 'failure_reason' => 'Account closed']);

    customerPage($this)
        ->assertSee('Payout failed: Account closed')
        ->call('editPayout', $refund->id)
        ->set('payoutMethod', 'upi')->set('upiId', 'buyer@okhdfc')
        ->call('savePayout')
        ->assertHasNoErrors();
    expect($refund->fresh()->payout_method)->toBe('upi');
});

test('another customer cannot change refund payout details', function () {
    $refund = CustomerRefund::create([
        'order_id' => $this->order->id, 'seller_order_id' => $this->sellerOrder->id, 'user_id' => $this->customer->id,
        'source_type' => ReturnRequest::REFUND_SOURCE, 'source_id' => (string) Str::ulid(), 'amount_paise' => 5000,
        'status' => RefundStatus::Pending,
    ]);

    expect(fn () => app(CustomerRefundServiceInterface::class)
        ->updatePayoutDetails($refund->id, User::factory()->create()->id, ['payout_method' => 'upi', 'upi_id' => 'x@okhdfc']))
        ->toThrow(ValidationException::class);
    expect($refund->fresh()->upi_id)->toBeNull();
});

test('seller returns tab lists only their own packages with returns', function () {
    ($this->request)([$this->toy->id => 1]);

    $otherSeller = SellerProfile::factory()->approved()->create(['user_id' => User::factory()->create()->id]);

    Livewire::actingAs(User::find($this->sellerProfile->user_id), 'web')->test('pages::seller.orders-index')
        ->set('status', 'returns')
        ->assertSeeHtml('data-test="open-return-badge"')
        ->assertSee($this->order->order_number);

    Livewire::actingAs(User::find($otherSeller->user_id), 'web')->test('pages::seller.orders-index')
        ->set('status', 'returns')
        ->assertDontSee($this->order->order_number);

    $this->actingAs(User::find($otherSeller->user_id), 'web')
        ->get(route('seller.orders.show', ['sellerOrder' => $this->sellerOrder->id]))->assertForbidden();
});

test('admin creates a return outside the window from the order page; a reason is required and logged', function () {
    $this->travelTo($this->deliveredAt->addDays(15));

    $page = Livewire::actingAs($this->admin, 'admin')->test('pages::admin.orders-show', ['order' => $this->order])
        ->assertSeeHtml('data-test="open-override-return-btn"')
        ->call('openOverrideReturn', $this->sellerOrder->id)
        ->assertSeeHtml('data-test="override-return-form"')
        ->set("overrideUnits.{$this->toy->id}", 1)
        ->set('overrideReturnReason', ReturnReason::SizeFit->value)
        ->call('createOverrideReturn')
        ->assertHasErrors('override');
    expect(ReturnRequest::count())->toBe(0);

    $page->set('overrideNote', 'Customer was in hospital')->call('createOverrideReturn')->assertHasNoErrors();

    $request = ReturnRequest::sole();
    expect($request->window_override_by_admin_id)->toBe($this->admin->id)
        ->and(AdminActivityLog::where('action', 'returns.window_override')->sole()->properties['reason'])->toBe('Customer was in hospital');
});

test('the return override needs returns.manage', function () {
    $staff = Admin::factory()->create();
    $staff->assignRole(Role::findOrCreate('Order viewer', 'admin')->givePermissionTo([AdminPermissions::ORDERS_VIEW, AdminPermissions::RETURNS_VIEW]));

    Livewire::actingAs($staff, 'admin')->test('pages::admin.orders-show', ['order' => $this->order])
        ->assertDontSeeHtml('data-test="open-override-return-btn"')
        ->call('openOverrideReturn', $this->sellerOrder->id)
        ->assertForbidden();

    Livewire::actingAs($staff, 'admin')->test('pages::admin.orders-show', ['order' => $this->order])
        ->set('overrideSellerOrderId', $this->sellerOrder->id)
        ->set("overrideUnits.{$this->toy->id}", 1)->set('overrideReturnReason', ReturnReason::SizeFit->value)->set('overrideNote', 'x')
        ->call('createOverrideReturn')
        ->assertForbidden();
    expect(ReturnRequest::count())->toBe(0);
});

test('returns queue filters by status, reason, seller, package and overdue seller report, with photos inline', function () {
    $claim = ($this->request)([$this->toy->id => 1], ReturnReason::Damaged, [UploadedFile::fake()->image('dent.jpg')]);
    $this->returns->approve($claim->id, $this->admin->id);
    $pickup = Shipment::where('direction', ShipmentDirection::Reverse)->sole();
    ($this->simulate)($pickup->id, ['picked_up', 'in_transit', 'delivered']);
    expect($claim->fresh()->status)->toBe(ReturnRequestStatus::Received);

    $photoUrl = route('admin.returns.request-photo', ['request' => $claim->id, 'kind' => 'customer', 'index' => 0]);
    $card = 'data-test="return-request-'.$claim->id.'"';

    ($this->queue)()->assertSeeHtml($card)->assertSeeHtml('src="'.$photoUrl.'"')
        ->set('filterReason', ReturnReason::SizeFit->value)->assertDontSeeHtml($card)
        ->set('filterReason', ReturnReason::Damaged->value)->assertSeeHtml($card)
        ->set('filterStatus', ReturnRequestStatus::Requested->value)->assertDontSeeHtml($card)
        ->set('filterStatus', ReturnRequestStatus::Received->value)->assertSeeHtml($card)
        ->set('filterSeller', (string) $this->sellerProfile->id)->assertSeeHtml($card)
        ->set('filterSeller', '999999')->assertDontSeeHtml($card)
        ->set('filterSeller', '')
        ->set('filterOverdueReport', true)->assertDontSeeHtml($card);

    $this->travelTo($claim->fresh()->report_deadline_at->addMinute());
    ($this->queue)()->set('filterOverdueReport', true)->assertSeeHtml($card)->assertSeeHtml('data-test="seller-report-missed"');

    Livewire::withQueryParams(['sellerOrder' => $this->sellerOrder->id, 'status' => 'all'])
        ->actingAs($this->admin, 'admin')->test('pages::admin.returns-index')
        ->assertSeeHtml($card)->assertSeeHtml('data-test="filter-seller-order"');
    Livewire::withQueryParams(['sellerOrder' => (string) Str::ulid()])
        ->actingAs($this->admin, 'admin')->test('pages::admin.returns-index')
        ->assertDontSeeHtml($card);

    // Thumbnails load on each render; the viewing is logged once per admin, request and hour
    $this->actingAs($this->admin, 'admin')->get($photoUrl)->assertOk();
    $this->actingAs($this->admin, 'admin')->get($photoUrl)->assertOk();
    expect(AdminActivityLog::where('action', 'returns.return_photo_viewed')->count())->toBe(1);
});

test('order and shipment pages link to the package\'s return requests', function () {
    $request = ($this->request)([$this->toy->id => 1]);
    $this->returns->approve($request->id, $this->admin->id);
    $pickup = Shipment::where('direction', ShipmentDirection::Reverse)->sole();
    $link = e(route('admin.returns.index', ['sellerOrder' => $this->sellerOrder->id, 'status' => 'all']));

    Livewire::actingAs($this->admin, 'admin')->test('pages::admin.orders-show', ['order' => $this->order])
        ->assertSeeHtml('data-test="order-returns-link"')->assertSeeHtml($link);
    Livewire::actingAs($this->admin, 'admin')->test('pages::admin.shipments-show', ['shipment' => $pickup->id])
        ->assertSeeHtml('data-test="shipment-return-request-link"')->assertSeeHtml($link);
    Livewire::actingAs($this->admin, 'admin')->test('pages::admin.shipments-show', ['shipment' => $this->original->id])
        ->assertDontSeeHtml('data-test="shipment-return-request-link"');
    ($this->queue)()->assertSeeHtml('data-test="return-order-link"');
});
