<?php

use App\Models\Admin;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\OrderItem;
use App\Orders\Models\SellerOrder;
use App\Settlements\Contracts\SettlementServiceInterface;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerOrderSettlement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['returns.default_return_window_days' => 7, 'returns.claim_window_days' => 5]);
    $this->travelTo(CarbonImmutable::parse('2026-10-29 10:00:00', 'UTC'));

    $category = Category::create(['name' => 'Kids']);
    $this->verticals = [
        'toys' => Vertical::create(['category_id' => $category->id, 'name' => 'Toys', 'hsn_code' => '9503', 'gst_rate' => 12, 'return_window_days' => 7]),
        'shoes' => Vertical::create(['category_id' => $category->id, 'name' => 'Shoes', 'hsn_code' => '6403', 'gst_rate' => 12, 'return_window_days' => 10]),
        'innerwear' => Vertical::create(['category_id' => $category->id, 'name' => 'Innerwear', 'hsn_code' => '6108', 'gst_rate' => 5, 'is_returnable' => false, 'return_window_days' => 15]),
        'default' => Vertical::create(['category_id' => $category->id, 'name' => 'Books', 'hsn_code' => '4901', 'gst_rate' => 0]),
    ];

    $this->sellerProfile = SellerProfile::factory()->approved()->create(['user_id' => User::factory()->create()->id]);
    $this->customer = User::factory()->create();

    $this->deliveredPackage = function (array $verticalKeys): SellerOrder {
        $order = Order::create([
            'order_number' => 'ORD-WIN-'.Str::upper(Str::random(6)),
            'user_id' => $this->customer->id,
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
            'seller_profile_id' => $this->sellerProfile->id,
            'seller_store_name' => $this->sellerProfile->store_name,
            'status' => SellerOrderStatus::Shipped,
            'subtotal' => 10000,
            'discount_total' => 0,
            'grand_total' => 10000,
            'placed_at' => now(),
        ]);

        foreach ($verticalKeys as $i => $key) {
            $product = Product::factory()->create(['vertical_id' => $this->verticals[$key]->id]);
            $variant = ProductVariant::factory()->create(['product_id' => $product->id]);
            OrderItem::create([
                'seller_order_id' => $sellerOrder->id,
                'listing_id' => null,
                'product_variant_id' => $variant->id,
                'sku' => "SKU-WIN-{$i}",
                'upid' => "UPID-WIN-{$i}",
                'seller_name' => $this->sellerProfile->store_name,
                'title' => "Item {$key}",
                'mrp' => 5000,
                'qty' => 1,
                'unit_price' => 5000,
                'line_total' => 5000,
            ]);
        }

        SellerOrderSettlement::create([
            'order_id' => $order->id,
            'seller_order_id' => $sellerOrder->id,
            'seller_profile_id' => $this->sellerProfile->id,
            'status' => SettlementStatus::Pending,
            'gross_amount' => 10000,
            'total_deductions' => 2000,
            'net_payout' => 8000,
            'return_window_days' => 7,
        ]);

        return $sellerOrder;
    };

    $this->holdFor = function (SellerOrder $sellerOrder): SellerOrderSettlement {
        app(SettlementServiceInterface::class)->markSettlementOnHold($sellerOrder->id, CarbonImmutable::now());

        return SellerOrderSettlement::where('seller_order_id', $sellerOrder->id)->sole();
    };
});

test('a package with 7-day and 10-day items is held for 10 days and stores the window used', function () {
    $settlement = ($this->holdFor)(($this->deliveredPackage)(['toys', 'shoes']));

    expect($settlement->status)->toBe(SettlementStatus::OnHold)
        ->and($settlement->return_window_days)->toBe(10)
        ->and($settlement->hold_until->format('Y-m-d H:i:s'))->toBe('2026-11-08 10:00:00');
});

test('a package with only non-returnable items uses the damaged/defective claim window', function () {
    // Innerwear has return_window_days 15, but it is not returnable: only claims (5 days here) apply
    $settlement = ($this->holdFor)(($this->deliveredPackage)(['innerwear']));

    expect($settlement->return_window_days)->toBe(5)
        ->and($settlement->hold_until->format('Y-m-d H:i:s'))->toBe('2026-11-03 10:00:00');
});

test('a vertical without its own window uses the default; mixed with non-returnable the longest wins', function () {
    expect(($this->holdFor)(($this->deliveredPackage)(['default']))->return_window_days)->toBe(7)
        ->and(($this->holdFor)(($this->deliveredPackage)(['innerwear', 'default']))->return_window_days)->toBe(7)
        ->and(($this->holdFor)(($this->deliveredPackage)(['innerwear', 'shoes']))->return_window_days)->toBe(10);
});

test('settlements already on hold keep their window when vertical windows change', function () {
    $sellerOrder = ($this->deliveredPackage)(['toys']);
    $settlement = ($this->holdFor)($sellerOrder);
    $holdUntil = $settlement->hold_until->format('Y-m-d H:i:s');

    $this->verticals['toys']->update(['return_window_days' => 30]);
    app(SettlementServiceInterface::class)->markSettlementOnHold($sellerOrder->id, CarbonImmutable::now()->addDay());

    $fresh = $settlement->fresh();
    expect($fresh->return_window_days)->toBe(7)
        ->and($fresh->hold_until->format('Y-m-d H:i:s'))->toBe($holdUntil);
});

test('admins set a vertical return window and returnability from the verticals page', function () {
    Artisan::call('permissions:sync');
    $admin = Admin::factory()->create();
    $admin->assignRole(Role::findOrCreate('Super Admin', 'admin'));
    $leaf = Category::create(['name' => 'Sneakers']);

    Livewire::actingAs($admin, 'admin')->test('pages::admin.catalog.verticals')
        ->call('openCreateModal')
        ->set('name', 'Running Shoes')
        ->set('category_id', $leaf->id)
        ->set('hsn_code', '6404')
        ->set('gst_rate', '12')
        ->set('return_window_days', '10')
        ->call('saveVertical')
        ->assertHasNoErrors();

    $vertical = Vertical::where('name', 'Running Shoes')->sole();
    expect($vertical->is_returnable)->toBeTrue()
        ->and($vertical->return_window_days)->toBe(10);

    Livewire::actingAs($admin, 'admin')->test('pages::admin.catalog.verticals')
        ->call('openEditModal', $vertical->id)
        ->assertSet('return_window_days', '10')
        ->set('is_returnable', false)
        ->set('return_window_days', '')
        ->call('saveVertical')
        ->assertHasNoErrors();

    expect($vertical->fresh()->is_returnable)->toBeFalse()
        ->and($vertical->fresh()->return_window_days)->toBeNull();

    Livewire::actingAs($admin, 'admin')->test('pages::admin.catalog.verticals')
        ->call('openEditModal', $vertical->id)
        ->set('return_window_days', '400')
        ->call('saveVertical')
        ->assertHasErrors(['return_window_days']);
});
