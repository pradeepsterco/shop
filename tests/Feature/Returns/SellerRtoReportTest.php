<?php

use App\Models\Admin;
use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Returns\Enums\RtoConditionStatus;
use App\Returns\Models\RtoIntakeReport;
use App\Shipping\Enums\ShipmentStatus;
use App\Shipping\Models\Shipment;
use App\Support\AdminPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('public');
    config(['returns.seller_rto_inspection_days' => 3, 'app.display_timezone' => 'Asia/Kolkata']);

    // Returned to the seller 28 Oct 2026 20:00 UTC; 3-day deadline = 31 Oct 20:00 UTC = 1 Nov 01:30 IST
    $this->travelTo(CarbonImmutable::parse('2026-10-29 06:00:00', 'UTC'));

    $this->sellerUser = User::factory()->create();
    $this->sellerProfile = SellerProfile::factory()->approved()->create(['user_id' => $this->sellerUser->id]);

    $order = Order::create([
        'order_number' => 'ORD-SELLER-RTO',
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

    $this->sellerOrder = SellerOrder::create([
        'order_id' => $order->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'seller_store_name' => $this->sellerProfile->store_name,
        'status' => SellerOrderStatus::ReturnedToOrigin,
        'subtotal' => 20000,
        'discount_total' => 0,
        'grand_total' => 20000,
        'placed_at' => now(),
        'rto_delivered_at' => CarbonImmutable::parse('2026-10-28 20:00:00', 'UTC'),
    ]);

    Shipment::create([
        'order_id' => $order->id,
        'seller_order_id' => $this->sellerOrder->id,
        'seller_profile_id' => $this->sellerProfile->id,
        'provider' => 'fake',
        'status' => ShipmentStatus::RtoDelivered,
        'awb' => 'SIM-SELLER-RTO',
        'actual_weight_grams' => 500,
        'volumetric_weight_grams' => 500,
        'chargeable_weight_grams' => 500,
        'length_cm' => 10,
        'width_cm' => 10,
        'height_cm' => 10,
    ]);

    $this->form = fn (?User $as = null) => Livewire::actingAs($as ?? $this->sellerUser)
        ->test('pages::seller.orders-show', ['sellerOrder' => $this->sellerOrder->id]);
});

test('seller submits a condition report with jpg, png and webp photos stored on the private disk', function () {
    ($this->form)()
        ->set('rtoCondition', 'damaged')
        ->set('rtoNotes', 'Box crushed')
        ->set('rtoPhotos', [
            UploadedFile::fake()->image('front.jpg'),
            UploadedFile::fake()->image('side.png'),
            UploadedFile::fake()->image('seal.webp'),
        ])
        ->call('submitRtoInspection')
        ->assertHasNoErrors()
        ->assertSee('RTO inspection report submitted successfully.');

    $report = RtoIntakeReport::where('seller_order_id', $this->sellerOrder->id)->sole();

    expect($report->condition_status)->toBe(RtoConditionStatus::Damaged)
        ->and($report->photos)->toHaveCount(3);

    foreach ($report->photos as $path) {
        Storage::disk('local')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }
});

test('only jpg, png and webp are accepted, at most five photos', function (array $files, string $errorKey) {
    ($this->form)()
        ->set('rtoPhotos', $files)
        ->call('submitRtoInspection')
        ->assertHasErrors([$errorKey]);

    expect(RtoIntakeReport::count())->toBe(0);
})->with([
    'gif' => [fn () => [UploadedFile::fake()->image('anim.gif')], 'rtoPhotos.0'],
    'pdf' => [fn () => [UploadedFile::fake()->create('report.pdf', 20, 'application/pdf')], 'rtoPhotos.0'],
    'six photos' => [fn () => array_map(fn ($i) => UploadedFile::fake()->image("p{$i}.jpg"), range(1, 6)), 'rtoPhotos'],
]);

test('a seller cannot report on another seller\'s order', function () {
    $otherSeller = User::factory()->create();
    SellerProfile::factory()->approved()->create(['user_id' => $otherSeller->id]);

    ($this->form)($otherSeller)->assertForbidden();

    expect(RtoIntakeReport::count())->toBe(0);
});

test('the report is read-only after submission', function () {
    ($this->form)()->set('rtoCondition', 'intact')->call('submitRtoInspection');

    ($this->form)()
        ->assertDontSee('data-test="submit-rto-report-btn"', false)
        ->assertSee('Condition Reported: INTACT')
        ->set('rtoCondition', 'damaged')
        ->call('submitRtoInspection')
        ->assertSee('An RTO condition report has already been submitted for this order.');

    expect(RtoIntakeReport::where('seller_order_id', $this->sellerOrder->id)->sole()->condition_status)->toBe(RtoConditionStatus::Intact);
});

test('the inspection deadline is shown in India time and enforced', function () {
    $this->actingAs($this->sellerUser)
        ->get(route('seller.orders.show', ['sellerOrder' => $this->sellerOrder->id]))
        ->assertOk()
        ->assertSee('01 Nov 2026, 1:30 AM IST');

    $this->travelTo(CarbonImmutable::parse('2026-10-31 20:00:01', 'UTC'));

    ($this->form)()
        ->call('submitRtoInspection')
        ->assertSee('The inspection window for this returned package has closed.');

    expect(RtoIntakeReport::count())->toBe(0);
});

test('rto photos are served only to the owning seller and to admins with returns.view', function () {
    ($this->form)()->set('rtoPhotos', [UploadedFile::fake()->image('front.jpg')])->call('submitRtoInspection');
    $report = RtoIntakeReport::sole();
    $sellerUrl = route('seller.returns.rto-photo', ['report' => $report->id, 'index' => 0]);
    $adminUrl = route('admin.returns.rto-photo', ['report' => $report->id, 'index' => 0]);

    $this->actingAs($this->sellerUser)->get($sellerUrl)->assertOk();

    $otherSeller = User::factory()->create();
    SellerProfile::factory()->approved()->create(['user_id' => $otherSeller->id]);
    $this->actingAs($otherSeller)->get($sellerUrl)->assertForbidden();

    Artisan::call('permissions:sync');
    $viewer = Admin::factory()->create();
    $viewer->assignRole(Role::findOrCreate('Returns Viewer', 'admin')->syncPermissions([AdminPermissions::RETURNS_VIEW]));
    $this->actingAs($viewer, 'admin')->get($adminUrl)->assertOk();

    $noReturns = Admin::factory()->create();
    $noReturns->assignRole(Role::findOrCreate('Shipping Only', 'admin')->syncPermissions([AdminPermissions::SHIPPING_VIEW]));
    $this->actingAs($noReturns, 'admin')->get($adminUrl)->assertForbidden();

    $this->actingAs($viewer, 'admin')
        ->get(route('admin.returns.rto-photo', ['report' => $report->id, 'index' => 3]))
        ->assertNotFound();
});
