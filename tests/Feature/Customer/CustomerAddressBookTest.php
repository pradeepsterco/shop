<?php

use App\Checkout\Services\CheckoutIntentService;
use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\CustomerAddress;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\User;
use App\Models\Vertical;
use App\Orders\DTOs\PlaceOrderData;
use App\Orders\Models\Order;
use App\Orders\Services\OrderService;
use App\Services\UpidGenerator;
use Illuminate\Support\Str;
use Livewire\Livewire;

function setupCustomerAddressContext(): array
{
    $customer1 = User::factory()->create();
    $customer2 = User::factory()->create();

    $addr1 = CustomerAddress::create([
        'user_id' => $customer1->id,
        'name' => 'Home Primary',
        'phone' => '9876543210',
        'pincode' => '400001',
        'locality' => 'Colaba',
        'address_line' => '1 Old Fort St',
        'city' => 'Mumbai',
        'state' => 'Maharashtra',
        'landmark' => 'Near Gateway',
        'alternate_phone' => '9111222333',
        'type' => 'home',
        'is_default' => true,
    ]);

    $addr2 = CustomerAddress::create([
        'user_id' => $customer1->id,
        'name' => 'Work Office',
        'phone' => '9876543211',
        'pincode' => '400051',
        'locality' => 'BKC',
        'address_line' => '501 One BKC',
        'city' => 'Mumbai',
        'state' => 'Maharashtra',
        'type' => 'work',
        'is_default' => false,
    ]);

    $cust2Addr = CustomerAddress::create([
        'user_id' => $customer2->id,
        'name' => 'Customer Two Home',
        'phone' => '9988776655',
        'pincode' => '560001',
        'locality' => 'MG Road',
        'address_line' => '10 Tech Park',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'type' => 'home',
        'is_default' => true,
    ]);

    return compact('customer1', 'customer2', 'addr1', 'addr2', 'cust2Addr');
}

test('customer can view address book with default address indicators', function () {
    $ctx = setupCustomerAddressContext();

    $this->actingAs($ctx['customer1']);

    Livewire::test('pages::storefront.address-index')
        ->assertSee('Home Primary')
        ->assertSee('1 Old Fort St')
        ->assertSee('Work Office')
        ->assertSee('501 One BKC')
        ->assertSee('Default')
        ->assertDontSee('Customer Two Home');
});

test('customer can add a new address with validation matching checkout rules', function () {
    $ctx = setupCustomerAddressContext();

    $this->actingAs($ctx['customer1']);

    // 1. Validation errors
    Livewire::test('pages::storefront.address-index')
        ->call('openCreateModal')
        ->set('name', '')
        ->set('phone', '123') // Invalid phone
        ->set('pincode', 'abc') // Invalid pin
        ->set('state', 'Invalid State')
        ->call('saveAddress')
        ->assertHasErrors(['name', 'phone', 'pincode', 'state', 'locality', 'address_line', 'city']);

    // 2. Successful creation
    Livewire::test('pages::storefront.address-index')
        ->call('openCreateModal')
        ->set('name', 'Vacation Villa')
        ->set('phone', '9899887766')
        ->set('pincode', '403516')
        ->set('locality', 'Calangute')
        ->set('address_line', 'Villa 4, Sea Breeze')
        ->set('city', 'North Goa')
        ->set('state', 'Goa')
        ->set('type', 'home')
        ->set('is_default', false)
        ->call('saveAddress')
        ->assertHasNoErrors()
        ->assertSee('Vacation Villa');

    expect(CustomerAddress::where('name', 'Vacation Villa')->exists())->toBeTrue();
});

test('customer can edit an existing address', function () {
    $ctx = setupCustomerAddressContext();

    $this->actingAs($ctx['customer1']);

    Livewire::test('pages::storefront.address-index')
        ->call('openEditModal', $ctx['addr2']->id)
        ->set('name', 'Work Office Updated')
        ->set('locality', 'BKC G-Block')
        ->call('saveAddress')
        ->assertHasNoErrors();

    expect($ctx['addr2']->fresh()->name)->toBe('Work Office Updated');
    expect($ctx['addr2']->fresh()->locality)->toBe('BKC G-Block');
});

test('setting an address as default unsets other defaults', function () {
    $ctx = setupCustomerAddressContext();

    $this->actingAs($ctx['customer1']);

    expect($ctx['addr1']->fresh()->is_default)->toBeTrue();
    expect($ctx['addr2']->fresh()->is_default)->toBeFalse();

    Livewire::test('pages::storefront.address-index')
        ->call('setDefault', $ctx['addr2']->id);

    expect($ctx['addr1']->fresh()->is_default)->toBeFalse();
    expect($ctx['addr2']->fresh()->is_default)->toBeTrue();
});

test('deleting default address rotates default to the most recent remaining address', function () {
    $ctx = setupCustomerAddressContext();

    $this->actingAs($ctx['customer1']);

    expect($ctx['addr1']->is_default)->toBeTrue();

    // Delete addr1 (the default)
    Livewire::test('pages::storefront.address-index')
        ->call('deleteAddress', $ctx['addr1']->id);

    expect(CustomerAddress::find($ctx['addr1']->id))->toBeNull();
    // addr2 should now automatically be promoted to default
    expect($ctx['addr2']->fresh()->is_default)->toBeTrue();
});

test('addresses added in checkout appear in address book and vice versa', function () {
    $brand = Brand::create(['name' => 'B'.uniqid(), 'slug' => 'b-'.uniqid(), 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()]);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'V'.uniqid(), 'slug' => 'v-'.uniqid(), 'hsn_code' => '9503', 'gst_rate' => 12.0]);
    $seller = SellerProfile::factory()->create(['status' => SellerStatus::Approved]);
    $prod = Product::create(['vertical_id' => $vert->id, 'brand_id' => $brand->id, 'title' => 'P'.uniqid(), 'slug' => 'p-'.uniqid(), 'description' => 'Test description', 'qc_status' => ProductQcStatus::Approved]);
    $var = ProductVariant::create(['product_id' => $prod->id, 'upid' => UpidGenerator::generate(), 'qc_status' => VariantQcStatus::Approved]);
    $listing = Listing::create(['seller_profile_id' => $seller->id, 'product_variant_id' => $var->id, 'sku' => 'SKU-'.uniqid(), 'mrp' => 100000, 'selling_price' => 80000, 'stock' => 5, 'status' => ListingStatus::Active]);

    $user = User::factory()->create();
    $this->actingAs($user);

    // 1. Add address via dashboard Address Book
    Livewire::test('pages::storefront.address-index')
        ->call('openCreateModal')
        ->set('name', 'Dashboard Address')
        ->set('phone', '9876500000')
        ->set('pincode', '400001')
        ->set('locality', 'Fort')
        ->set('address_line', '1 Bank Street')
        ->set('city', 'Mumbai')
        ->set('state', 'Maharashtra')
        ->set('type', 'home')
        ->set('is_default', true)
        ->call('saveAddress');

    // 2. Open checkout -> Dashboard Address appears
    $intentService = app(CheckoutIntentService::class);
    $intent = $intentService->createOrReuseBuyNowIntent($user, $listing->id);

    Livewire::test('pages::storefront.checkout-summary', ['intent' => $intent->id])
        ->assertSee('Dashboard Address')
        ->assertSee('1 Bank Street');

    // 3. Add address via Checkout
    Livewire::test('pages::storefront.checkout-summary', ['intent' => $intent->id])
        ->set('addressName', 'Checkout Created Address')
        ->set('addressPhone', '9876511111')
        ->set('addressPincode', '400050')
        ->set('addressLocality', 'Bandra')
        ->set('addressLine', '20 Hill Road')
        ->set('addressCity', 'Mumbai')
        ->set('addressState', 'Maharashtra')
        ->set('addressType', 'work')
        ->set('addressIsDefault', false)
        ->call('saveNewAddress');

    // 4. Address Book shows the Checkout Created Address
    Livewire::test('pages::storefront.address-index')
        ->assertSee('Checkout Created Address')
        ->assertSee('20 Hill Road');
});

test('order address snapshot remains intact after customer deletes that address from dashboard', function () {
    $ctx = setupCustomerAddressContext();
    $brand = Brand::create(['name' => 'B'.uniqid(), 'slug' => 'b-'.uniqid(), 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()]);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'V'.uniqid(), 'slug' => 'v-'.uniqid(), 'hsn_code' => '9503', 'gst_rate' => 12.0]);
    $seller = SellerProfile::factory()->create(['status' => SellerStatus::Approved]);
    $prod = Product::create(['vertical_id' => $vert->id, 'brand_id' => $brand->id, 'title' => 'P'.uniqid(), 'slug' => 'p-'.uniqid(), 'description' => 'Test description', 'qc_status' => ProductQcStatus::Approved]);
    $var = ProductVariant::create(['product_id' => $prod->id, 'upid' => UpidGenerator::generate(), 'qc_status' => VariantQcStatus::Approved]);
    $listing = Listing::create(['seller_profile_id' => $seller->id, 'product_variant_id' => $var->id, 'sku' => 'SKU-'.uniqid(), 'mrp' => 100000, 'selling_price' => 80000, 'stock' => 5, 'status' => ListingStatus::Active]);

    $this->actingAs($ctx['customer1']);

    // Place an order using addr1
    $orderService = app(OrderService::class);
    $order = $orderService->place(new PlaceOrderData(
        userId: $ctx['customer1']->id,
        addressId: $ctx['addr1']->id,
        paymentMethod: 'cash_on_delivery',
        lines: [
            ['listing_id' => $listing->id, 'qty' => 1],
        ],
        expectedTotal: 80000,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    ));

    // Verify initial snapshot
    expect($order->delivery_address['name'])->toBe('Home Primary');
    expect($order->delivery_address['phone'])->toBe('9876543210');

    // Delete addr1 from address book
    Livewire::test('pages::storefront.address-index')
        ->call('deleteAddress', $ctx['addr1']->id);

    expect(CustomerAddress::find($ctx['addr1']->id))->toBeNull();

    // Verify order delivery_address is 100% intact
    $freshOrder = $order->fresh();
    expect($freshOrder->delivery_address['name'])->toBe('Home Primary');
    expect($freshOrder->delivery_address['phone'])->toBe('9876543210');
    expect($freshOrder->delivery_address['address_line'])->toBe('1 Old Fort St');
    expect($freshOrder->delivery_address['city'])->toBe('Mumbai');
    expect($freshOrder->delivery_state)->toBe('Maharashtra');
});

test('another customer gets 403 when attempting to edit, delete or setDefault on another user address', function () {
    $ctx = setupCustomerAddressContext();

    // Customer 2 attempts to open Customer 1's address
    $this->actingAs($ctx['customer2']);

    Livewire::test('pages::storefront.address-index')
        ->call('openEditModal', $ctx['addr1']->id)
        ->assertForbidden();

    // Customer 1 mounts component, then Customer 2 calls actions
    $this->actingAs($ctx['customer1']);
    $c1 = Livewire::test('pages::storefront.address-index');
    $this->actingAs($ctx['customer2']);
    $c1->call('setDefault', $ctx['addr1']->id)->assertForbidden();

    $this->actingAs($ctx['customer1']);
    $c2 = Livewire::test('pages::storefront.address-index');
    $this->actingAs($ctx['customer2']);
    $c2->call('deleteAddress', $ctx['addr1']->id)->assertForbidden();
});

test('address deleted in dashboard while selected in checkout blocks order placement with friendly message', function () {
    $brand = Brand::create(['name' => 'B'.uniqid(), 'slug' => 'b-'.uniqid(), 'status' => BrandStatus::Approved]);
    $cat = Category::create(['name' => 'C'.uniqid(), 'slug' => 'c-'.uniqid()]);
    $vert = Vertical::create(['category_id' => $cat->id, 'name' => 'V'.uniqid(), 'slug' => 'v-'.uniqid(), 'hsn_code' => '9503', 'gst_rate' => 12.0]);
    $seller = SellerProfile::factory()->create(['status' => SellerStatus::Approved]);
    $prod = Product::create(['vertical_id' => $vert->id, 'brand_id' => $brand->id, 'title' => 'P'.uniqid(), 'slug' => 'p-'.uniqid(), 'description' => 'Test description', 'qc_status' => ProductQcStatus::Approved]);
    $var = ProductVariant::create(['product_id' => $prod->id, 'upid' => UpidGenerator::generate(), 'qc_status' => VariantQcStatus::Approved]);
    $listing = Listing::create(['seller_profile_id' => $seller->id, 'product_variant_id' => $var->id, 'sku' => 'SKU-'.uniqid(), 'mrp' => 100000, 'selling_price' => 80000, 'stock' => 5, 'status' => ListingStatus::Active]);

    $customer = User::factory()->create();
    $addr = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'Temp Address',
        'phone' => '9876543210',
        'pincode' => '400001',
        'locality' => 'Colaba',
        'address_line' => '1 Old Fort St',
        'city' => 'Mumbai',
        'state' => 'Maharashtra',
        'type' => 'home',
        'is_default' => true,
    ]);

    $this->actingAs($customer);

    $intentService = app(CheckoutIntentService::class);
    $intent = $intentService->createOrReuseBuyNowIntent($customer, $listing->id);

    // Mount checkout with addr selected
    $checkout = Livewire::test('pages::storefront.checkout-summary', ['intent' => $intent->id])
        ->assertSet('selectedAddressId', $addr->id);

    // In background / dashboard, the address is deleted
    $addr->delete();

    // Customer clicks confirm order on checkout
    $checkout->call('confirmOrder')
        ->assertSee('Please select a delivery address before confirming your order.')
        ->assertSet('selectedAddressId', null)
        ->assertSet('currentStep', 2);

    // No order is created
    expect(Order::where('checkout_intent_id', $intent->id)->exists())->toBeFalse();
});
