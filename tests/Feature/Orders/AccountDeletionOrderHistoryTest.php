<?php

use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Admin;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomerAddress;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerDocument;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Orders\DTOs\PlaceOrderData;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Orders\Services\OrderService;
use App\Services\UpidGenerator;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function setupOrderHistoryContext(): array
{
    $brand = Brand::create(['name' => 'ToyCraft', 'slug' => 'toycraft-'.uniqid(), 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'Toys', 'slug' => 'toys-'.uniqid()]);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'Action Figures', 'slug' => 'action-figures-'.uniqid(), 'hsn_code' => '9503', 'gst_rate' => 18.0]);

    $sellerUser = User::factory()->create();
    $seller = SellerProfile::factory()->create([
        'user_id' => $sellerUser->id,
        'status' => SellerStatus::Approved,
        'store_name' => 'Legacy Store Ltd',
        'gstin' => '27AAACL1234F1Z1',
        'pickup_state' => 'Maharashtra',
    ]);

    $product = Product::create([
        'vertical_id' => $vert->id,
        'brand_id' => $brand->id,
        'title' => 'Collector Edition Toy',
        'slug' => 'collector-toy-'.uniqid(),
        'description' => 'Rare toy',
        'qc_status' => ProductQcStatus::Approved,
    ]);

    $image = ProductImage::create([
        'product_id' => $product->id,
        'path' => 'products/collector.jpg',
        'is_primary' => true,
        'status' => ProductImageStatus::Live,
        'sort_order' => 1,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => UpidGenerator::generate(),
        'variant_attributes' => ['edition' => 'gold'],
        'attribute_snapshot' => ['edition' => ['value' => 'gold', 'label' => 'Gold Edition']],
        'qc_status' => VariantQcStatus::Approved,
    ]);

    $listing = Listing::create([
        'seller_profile_id' => $seller->id,
        'product_variant_id' => $variant->id,
        'sku' => 'SKU-GOLD-'.uniqid(),
        'mrp' => 500000,
        'selling_price' => 399900,
        'stock' => 5,
        'status' => ListingStatus::Active,
    ]);

    $customer = User::factory()->create();
    $address = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'Amit Kumar',
        'phone' => '9123456789',
        'pincode' => '400001',
        'locality' => 'Colaba',
        'address_line' => '10 Marine Drive',
        'city' => 'Mumbai',
        'state' => 'Maharashtra',
        'type' => 'home',
        'is_default' => true,
    ]);

    return compact('sellerUser', 'seller', 'customer', 'address', 'listing', 'variant', 'product');
}

test('customer account deletion is blocked when customer has open orders', function () {
    $ctx = setupOrderHistoryContext();

    $orderService = app(OrderService::class);
    $order = $orderService->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        expectedTotal: 399900,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    ));

    expect($order->status)->toBe(OrderStatus::Placed);
    expect($ctx['customer']->hasOpenOrders())->toBeTrue();

    // Attempting to delete the customer must throw DomainException
    expect(fn () => $ctx['customer']->delete())
        ->toThrow(DomainException::class, 'Cannot delete customer account with active orders.');

    expect($ctx['customer']->exists())->toBeTrue();
});

test('seller account deletion is blocked when seller has open/undelivered orders', function () {
    $ctx = setupOrderHistoryContext();

    $orderService = app(OrderService::class);
    $order = $orderService->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        expectedTotal: 399900,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    ));

    $sellerOrder = $order->sellerOrders->first();
    expect($sellerOrder->status)->toBe(SellerOrderStatus::Placed);
    expect($ctx['seller']->hasOpenOrders())->toBeTrue();

    // Attempting to delete the seller profile directly must throw DomainException
    expect(fn () => $ctx['seller']->delete())
        ->toThrow(DomainException::class, 'Cannot delete seller account with active or undelivered orders.');

    // Attempting to delete the seller user must also be blocked
    expect(fn () => $ctx['sellerUser']->delete())
        ->toThrow(DomainException::class, 'Cannot delete');

    expect($ctx['seller']->exists())->toBeTrue();
});

test('when seller account is deleted after orders are delivered or cancelled, order history and snapshots remain intact', function () {
    $ctx = setupOrderHistoryContext();

    $orderService = app(OrderService::class);
    $order = $orderService->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        expectedTotal: 399900,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    ));

    $sellerOrder = $order->sellerOrders->first();

    // Complete the seller order delivery
    $sellerOrder->update([
        'status' => SellerOrderStatus::Delivered,
        'delivered_at' => now(),
    ]);

    // Parent order also becomes completed
    $order->update([
        'status' => OrderStatus::Completed,
    ]);

    expect($ctx['seller']->hasOpenOrders())->toBeFalse();

    // Deleting the seller profile succeeds
    $sellerProfileId = $ctx['seller']->id;
    $ctx['seller']->delete();

    expect(SellerProfile::find($sellerProfileId))->toBeNull();

    // Seller order still exists with null seller_profile_id and all historical snapshots intact
    $freshSellerOrder = SellerOrder::with('items')->find($sellerOrder->id);
    expect($freshSellerOrder)->not->toBeNull();
    expect($freshSellerOrder->seller_profile_id)->toBeNull();
    expect($freshSellerOrder->seller_store_name)->toBe('Legacy Store Ltd');
    expect($freshSellerOrder->seller_gstin)->toBe('27AAACL1234F1Z1');
    expect($freshSellerOrder->seller_pickup_state)->toBe('Maharashtra');

    // Order item snapshots remain fully intact
    $orderItem = $freshSellerOrder->items->first();
    expect($orderItem)->not->toBeNull();
    expect($orderItem->title)->toBe('Collector Edition Toy');
    expect($orderItem->seller_name)->toBe('Legacy Store Ltd');
    expect($orderItem->unit_price)->toBe(399900);
    expect($orderItem->line_total)->toBe(399900);
    expect($orderItem->hsn_code)->toBe('9503');
    expect($orderItem->gst_rate)->toEqual(18.0);

    // Parent order remains fully intact
    $freshOrder = Order::find($order->id);
    expect($freshOrder)->not->toBeNull();
    expect($freshOrder->status)->toBe(OrderStatus::Completed);
    expect($freshOrder->grand_total)->toBe(399900);
});

test('account deletion with open orders shows a friendly validation error on delete-user modal', function () {
    $ctx = setupOrderHistoryContext();

    $orderService = app(OrderService::class);
    $order = $orderService->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        expectedTotal: 399900,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    ));

    expect($order->status)->toBe(OrderStatus::Placed);
    expect($ctx['customer']->hasOpenOrders())->toBeTrue();

    // 1. Customer with open customer orders is blocked
    $this->actingAs($ctx['customer']);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors(['password' => 'Cannot delete your account while you have active or undelivered orders (as customer or seller).']);

    expect($ctx['customer']->fresh()->exists)->toBeTrue();

    // 2. Seller with open seller orders is also blocked
    $this->actingAs($ctx['sellerUser']);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertHasErrors(['password' => 'Cannot delete your account while you have active or undelivered orders (as customer or seller).']);

    expect($ctx['sellerUser']->fresh()->exists)->toBeTrue();
});

test('customer with only delivered orders can delete account which anonymizes user, prevents login, and preserves order history and snapshots', function () {
    $ctx = setupOrderHistoryContext();

    $orderService = app(OrderService::class);
    $order = $orderService->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        expectedTotal: 399900,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    ));

    // Mark seller order delivered and parent completed
    $sellerOrder = $order->sellerOrders->first();
    $sellerOrder->update(['status' => SellerOrderStatus::Delivered, 'delivered_at' => now()]);
    $order->update(['status' => OrderStatus::Completed]);

    expect($ctx['customer']->hasOpenOrders())->toBeFalse();
    $originalEmail = $ctx['customer']->email;

    // Customer deletes account via modal
    $this->actingAs($ctx['customer']);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertRedirect('/');

    // User row is anonymized, not hard-deleted
    $customer = $ctx['customer']->fresh();
    expect($customer)->not->toBeNull();
    expect($customer->name)->toBe('Deleted user');
    expect($customer->email)->toBe("deleted+{$customer->id}@invalid");
    expect($customer->phone)->toBeNull();
    expect($customer->is_active)->toBeFalse();
    expect($customer->addresses)->toHaveCount(0);

    // Customer cannot log in anymore
    expect(auth()->attempt(['email' => $originalEmail, 'password' => 'password']))->toBeFalse();
    expect(auth()->attempt(['email' => $customer->email, 'password' => 'password']))->toBeFalse();

    // Order history and address snapshots remain intact
    $freshOrder = Order::with('sellerOrders.items')->find($order->id);
    expect($freshOrder)->not->toBeNull();
    expect($freshOrder->status)->toBe(OrderStatus::Completed);
    expect($freshOrder->delivery_address['name'])->toBe('Amit Kumar');
    expect($freshOrder->delivery_address['phone'])->toBe('9123456789');
    expect($freshOrder->sellerOrders->first()->items->first()->title)->toBe('Collector Edition Toy');
});

test('customer with no orders is hard-deleted upon account deletion', function () {
    $user = User::factory()->create(['password' => bcrypt('password')]);
    $userId = $user->id;

    $this->actingAs($user);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertRedirect('/');

    expect(User::find($userId))->toBeNull();
});

test('seller with only delivered orders deletes account: profile anonymized, listings deactivated, snapshots intact, admin shows Deleted seller without placeholder data', function () {
    Storage::fake('local');
    Storage::fake('public');

    $ctx = setupOrderHistoryContext();
    $seller = $ctx['seller'];
    $sellerUser = $ctx['sellerUser'];

    // Setup files on seller
    Storage::disk('public')->put('seller-logos/test-logo.png', 'logo content');
    Storage::disk('public')->put('seller-banners/test-banner.png', 'banner content');
    Storage::disk('local')->put('kyc-documents/pan.pdf', 'pan content');

    $seller->update([
        'logo_path' => 'seller-logos/test-logo.png',
        'banner_path' => 'seller-banners/test-banner.png',
        'pan' => 'ABCDE1234F',
        'bank_account_holder' => 'Amit Seller',
        'bank_account_number' => '123456789012',
        'ifsc' => 'HDFC0001234',
    ]);

    SellerDocument::create([
        'seller_profile_id' => $seller->id,
        'type' => 'pan_card',
        'path' => 'kyc-documents/pan.pdf',
        'original_name' => 'pan.pdf',
    ]);

    // Place and deliver order
    $orderService = app(OrderService::class);
    $order = $orderService->place(new PlaceOrderData(
        userId: $ctx['customer']->id,
        addressId: $ctx['address']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $ctx['listing']->id, 'qty' => 1],
        ],
        expectedTotal: 399900,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    ));

    $sellerOrder = $order->sellerOrders->first();
    $sellerOrder->update(['status' => SellerOrderStatus::Delivered, 'delivered_at' => now()]);
    $order->update(['status' => OrderStatus::Completed]);

    // Seller deletes their account via modal
    $this->actingAs($sellerUser);

    Livewire::test('pages::settings.delete-user-modal')
        ->set('password', 'password')
        ->call('deleteUser')
        ->assertRedirect('/');

    // 1. Seller Profile is anonymized
    $freshSeller = $seller->fresh();
    expect($freshSeller->store_name)->toBe('Deleted seller');
    expect($freshSeller->status)->toBe(SellerStatus::Suspended);
    expect($freshSeller->pan)->toBe('XXXXX0000X');
    expect($freshSeller->gstin)->toBeNull();
    expect($freshSeller->bank_account_holder)->toBe('Deleted Seller');
    expect($freshSeller->bank_account_number)->toBe('000000000000');
    expect($freshSeller->ifsc)->toBe('XXXX0000000');
    expect($freshSeller->logo_path)->toBeNull();
    expect($freshSeller->banner_path)->toBeNull();

    // KYC, logo and banner files removed from storage
    Storage::disk('public')->assertMissing('seller-logos/test-logo.png');
    Storage::disk('public')->assertMissing('seller-banners/test-banner.png');
    Storage::disk('local')->assertMissing('kyc-documents/pan.pdf');

    // 2. Seller's listings no longer appear on storefront
    $freshListing = $ctx['listing']->fresh();
    expect($freshListing->status)->toBe(ListingStatus::Inactive);
    $ctx['product']->refresh();
    expect($ctx['product']->buyBoxListing)->toBeNull();

    // 3. Seller Order snapshots remain unchanged
    $freshSellerOrder = $sellerOrder->fresh();
    expect($freshSellerOrder->seller_store_name)->toBe('Legacy Store Ltd');
    expect($freshSellerOrder->seller_gstin)->toBe('27AAACL1234F1Z1');
    expect($freshSellerOrder->items->first()->seller_name)->toBe('Legacy Store Ltd');
    expect($freshSellerOrder->items->first()->title)->toBe('Collector Edition Toy');

    // 4. Admin seller pages show anonymized profiles as "Deleted seller" without placeholder bank/PAN values
    Role::findOrCreate('Super Admin', 'admin');
    $admin = Admin::factory()->create();
    $admin->assignRole('Super Admin');
    $this->actingAs($admin, 'admin');

    // Admin index shows "Deleted seller"
    Livewire::test('pages::admin.sellers-index')
        ->assertSee('Deleted seller')
        ->assertSee('Deleted user');

    // Admin details shows "Deleted seller" without placeholder values (shows '—')
    Livewire::test('pages::admin.sellers-show', ['sellerProfile' => $freshSeller])
        ->assertSee('Deleted seller')
        ->assertDontSee('XXXXX0000X')
        ->assertDontSee('000000000000')
        ->assertDontSee('Deleted Seller')
        ->assertSee('—');
});
