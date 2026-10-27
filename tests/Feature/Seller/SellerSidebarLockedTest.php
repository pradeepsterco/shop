<?php

use App\Enums\SellerStatus;
use App\Fees\Models\FeeClosingSlab;
use App\Fees\Models\FeeCommissionSlab;
use App\Fees\Models\FeeSchedule;
use App\Fees\Models\FeeShippingSlab;
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
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Events\SellerOrderShipped;
use App\Orders\Models\SellerOrder;
use App\Orders\Services\OrderService;
use App\Shared\Enums\FeeScheduleStatus;
use App\Shared\Enums\ShippingZone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('user with no seller profile sees start setting up status card with 0 percent progress and can access fee calculator', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('seller.fees.calculator'));

    $response->assertOk()
        ->assertSee('Start setting up your store — Step 1 of 5')
        ->assertSee('0%')
        ->assertSee('role="progressbar"', false)
        ->assertSee('aria-valuenow="0"', false)
        ->assertSee('Continue setup')
        ->assertSee(route('seller.apply'))
        ->assertSee('aria-disabled="true"', false)
        ->assertDontSee('href="'.route('seller.dashboard').'"', false)
        ->assertSee('href="'.route('seller.fees.calculator').'"', false)
        ->assertSee('Switch to customer');
});

test('non-approved draft seller sees locked sidebar items with aria-disabled and no links', function () {
    $user = User::factory()->create();
    $profile = SellerProfile::factory()->draft()->create([
        'user_id' => $user->id,
        'current_step' => 2,
    ]);

    $response = $this->actingAs($user)->get(route('seller.fees.calculator'));

    $response->assertOk()
        // Status card for draft with progress and next step
        ->assertSee('Setup In Progress')
        ->assertSee('Step 2/5')
        ->assertSee('Contact & Address')
        ->assertSee('Continue setup')
        ->assertSee(route('seller.apply'))
        // Locked items have aria-disabled="true" and title
        ->assertSee('aria-disabled="true"', false)
        ->assertSee('title="Unlocks when your store is approved"', false)
        ->assertSee('aria-label="Unlocks when your store is approved"', false)
        // Ensure role="link" is NOT used for locked items
        ->assertDontSee('role="link"', false)
        // Store features are locked (not links)
        ->assertDontSee('href="'.route('seller.dashboard').'"', false)
        ->assertDontSee('href="'.route('seller.orders.index').'"', false)
        ->assertDontSee('href="'.route('seller.products.index').'"', false)
        ->assertDontSee('href="'.route('seller.products.create').'"', false)
        ->assertDontSee('href="'.route('seller.payments.index').'"', false)
        ->assertDontSee('href="'.route('seller.profile').'"', false)
        ->assertDontSee('href="'.route('seller.settings').'"', false)
        // Fee Calculator is unlocked as teaser
        ->assertSee('href="'.route('seller.fees.calculator').'"', false)
        // Common account actions stay active
        ->assertSee('href="'.route('dashboard').'"', false)
        ->assertSee('Switch to customer')
        ->assertSee('Log out');
});

test('pending seller sees under review status card without progress bar and locked items', function () {
    $user = User::factory()->create();
    SellerProfile::factory()->pending()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('seller.fees.calculator'));

    $response->assertOk()
        ->assertSee('Under review')
        ->assertSee('Your application is being reviewed by our team')
        ->assertDontSee('role="progressbar"', false)
        ->assertSee('aria-disabled="true"', false)
        ->assertDontSee('href="'.route('seller.dashboard').'"', false);
});

test('rejected seller sees action needed status card with rejection reason and fix button', function () {
    $user = User::factory()->create();
    SellerProfile::factory()->rejected()->create([
        'user_id' => $user->id,
        'rejection_reason' => 'Invalid bank document provided. Please upload cancelled cheque.',
    ]);

    $response = $this->actingAs($user)->get(route('seller.fees.calculator'));

    $response->assertOk()
        ->assertSee('Action needed')
        ->assertSee('Invalid bank document provided. Please upload cancelled cheque.')
        ->assertSee('Fix & resubmit', false)
        ->assertSee(route('seller.apply'))
        ->assertSee('aria-disabled="true"', false);
});

test('suspended seller sees store suspended card with orders and payments unlocked', function () {
    $user = User::factory()->create();
    SellerProfile::factory()->suspended()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('seller.fees.calculator'));

    $response->assertOk()
        ->assertSee('Store suspended — contact support')
        // Orders and Payments are UNLOCKED for suspended sellers to fulfill existing orders
        ->assertSee('href="'.route('seller.orders.index').'"', false)
        ->assertSee('href="'.route('seller.payments.index').'"', false)
        // Other items are locked
        ->assertDontSee('href="'.route('seller.dashboard').'"', false)
        ->assertDontSee('href="'.route('seller.products.index').'"', false)
        ->assertDontSee('href="'.route('seller.products.create').'"', false)
        ->assertDontSee('href="'.route('seller.profile').'"', false)
        ->assertDontSee('href="'.route('seller.settings').'"', false);
});

test('approved seller sees the normal menu without status card and all links active', function () {
    $user = User::factory()->create();
    SellerProfile::factory()->approved()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('seller.dashboard'));

    $response->assertOk()
        // No status card rendered
        ->assertDontSee('Setup In Progress')
        ->assertDontSee('Under review')
        ->assertDontSee('Action needed')
        ->assertDontSee('Store suspended — contact support')
        // No locked items
        ->assertDontSee('aria-disabled="true"', false)
        ->assertDontSee('title="Unlocks when your store is approved"', false)
        // All links are active
        ->assertSee('href="'.route('seller.dashboard').'"', false)
        ->assertSee('href="'.route('seller.orders.index').'"', false)
        ->assertSee('href="'.route('seller.products.index').'"', false)
        ->assertSee('href="'.route('seller.products.create').'"', false)
        ->assertSee('href="'.route('seller.fees.calculator').'"', false)
        ->assertSee('href="'.route('seller.payments.index').'"', false)
        ->assertSee('href="'.route('seller.profile').'"', false)
        ->assertSee('href="'.route('seller.settings').'"', false);
});

test('single role switch button in topbar is accessible on seller pages with customer switch', function () {
    $user = User::factory()->create();
    SellerProfile::factory()->approved()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('seller.dashboard'));

    $response->assertOk()
        ->assertSee('data-test="role-switch-button"', false)
        ->assertSee('Switch to customer')
        ->assertSee('Customer')
        ->assertSee('href="'.route('dashboard').'"', false);
});

test('single role switch button in topbar is accessible on customer pages with dynamic label per state', function ($stateFactory, string $expectedDesktopLabel, string $expectedMobileLabel, string $expectedRouteName) {
    $user = User::factory()->create();
    $stateFactory($user);

    $response = $this->actingAs($user)->get(route('dashboard'));

    $response->assertOk()
        ->assertSee('data-test="role-switch-button"', false)
        ->assertSee($expectedDesktopLabel)
        ->assertSee($expectedMobileLabel)
        ->assertSee('href="'.route($expectedRouteName).'"', false);
})->with([
    'no profile' => [
        fn (User $user) => null,
        'Become a seller',
        'Sell',
        'seller.apply',
    ],
    'draft' => [
        fn (User $user) => SellerProfile::factory()->draft()->create(['user_id' => $user->id]),
        'Seller Center',
        'Seller Center',
        'seller.apply',
    ],
    'pending' => [
        fn (User $user) => SellerProfile::factory()->pending()->create(['user_id' => $user->id]),
        'Seller Center',
        'Seller Center',
        'seller.apply',
    ],
    'rejected' => [
        fn (User $user) => SellerProfile::factory()->rejected()->create(['user_id' => $user->id]),
        'Seller Center',
        'Seller Center',
        'seller.apply',
    ],
    'suspended' => [
        fn (User $user) => SellerProfile::factory()->suspended()->create(['user_id' => $user->id]),
        'Seller Center',
        'Seller Center',
        'seller.apply',
    ],
    'approved' => [
        fn (User $user) => SellerProfile::factory()->approved()->create(['user_id' => $user->id]),
        'Switch to seller',
        'Seller',
        'seller.dashboard',
    ],
]);

test('account dropdown contains only user info and logout without duplicate switch buttons', function () {
    $user = User::factory()->create();
    SellerProfile::factory()->approved()->create(['user_id' => $user->id]);

    $response = $this->actingAs($user)->get(route('seller.dashboard'));

    $response->assertOk()
        ->assertSee($user->name)
        ->assertSee($user->email)
        ->assertSee('Log out')
        ->assertSee('data-test="logout-button"', false);

    $html = $response->getContent();
    preg_match('/<div\b[^>]*data-test="account-menu-dropdown"[^>]*>(.*?)<\/div>\s*<\/div>/is', $html, $matches);
    $dropdownHtml = $matches[1] ?? '';

    // No switch button or customer navigation inside account dropdown
    expect($dropdownHtml)->not->toContain('Switch to customer')
        ->and($dropdownHtml)->not->toContain('Switch to seller')
        ->and($dropdownHtml)->not->toContain('route(\'orders.index\')');
});

test('sidebars and mobile menus do not render duplicate switch buttons', function () {
    $sellerUser = User::factory()->create();
    SellerProfile::factory()->approved()->create(['user_id' => $sellerUser->id]);

    $sellerResponse = $this->actingAs($sellerUser)->get(route('seller.dashboard'));
    $sellerHtml = $sellerResponse->getContent();

    preg_match('/<aside\b[^>]*data-test="seller-sidebar"[^>]*>(.*?)<\/aside>/is', $sellerHtml, $sSidebar);
    preg_match('/<div\b[^>]*data-test="seller-mobile-menu"[^>]*>(.*?)<\/div>\s*<\/div>/is', $sellerHtml, $sMobile);

    expect($sSidebar[1] ?? '')->not->toContain('Switch to customer')
        ->and($sMobile[1] ?? '')->not->toContain('Switch to customer');

    $customerUser = User::factory()->create();
    $customerResponse = $this->actingAs($customerUser)->get(route('dashboard'));
    $customerHtml = $customerResponse->getContent();

    preg_match('/<aside\b[^>]*data-test="customer-sidebar"[^>]*>(.*?)<\/aside>/is', $customerHtml, $cSidebar);
    preg_match('/<div\b[^>]*data-test="customer-mobile-menu"[^>]*>(.*?)<\/div>\s*<\/div>/is', $customerHtml, $cMobile);

    expect($cSidebar[1] ?? '')->not->toContain('Become a seller')
        ->and($cSidebar[1] ?? '')->not->toContain('Seller Center')
        ->and($cSidebar[1] ?? '')->not->toContain('Switch to seller')
        ->and($cMobile[1] ?? '')->not->toContain('Become a seller')
        ->and($cMobile[1] ?? '')->not->toContain('Seller Center')
        ->and($cMobile[1] ?? '')->not->toContain('Switch to seller');
});

test('fee calculator works for draft seller with missing pickup pincode and PAN', function () {
    $schedule = FeeSchedule::create([
        'name' => 'Active Sched',
        'version' => 1,
        'status' => FeeScheduleStatus::Active,
        'effective_from' => now()->subDay(),
        'gst_rate' => 18.00,
        'tcs_rate' => 0.50,
        'tds_standard_rate' => 0.10,
        'tds_no_pan_rate' => 5.00,
        'default_weight_grams' => 500,
    ]);
    FeeCommissionSlab::create(['fee_schedule_id' => $schedule->id, 'min_price' => 0, 'max_price' => null, 'rate_bps' => 1000]);
    FeeClosingSlab::create(['fee_schedule_id' => $schedule->id, 'min_price' => 0, 'max_price' => null, 'fee_amount' => 2000]);
    FeeShippingSlab::create(['fee_schedule_id' => $schedule->id, 'min_weight_grams' => 0, 'max_weight_grams' => null, 'zone' => ShippingZone::National, 'fee_amount' => 5000, 'is_default' => true]);

    $user = User::factory()->create();
    $profile = SellerProfile::factory()->draft()->create([
        'user_id' => $user->id,
        'pan' => '',
        'pickup_postal_code' => '',
    ]);

    Livewire::actingAs($user)
        ->test('pages::seller.fee-calculator')
        ->assertSet('hasAssumptions', true)
        ->assertSet('zone', 'national')
        ->assertSet('hasPan', true)
        ->assertHasNoErrors()
        ->assertSee('Preview Assumptions Applied')
        ->assertSee('National shipping zone')
        ->assertSee('Net Bank Payout');
});

test('locked routes still redirect non-approved seller to onboarding wizard on direct URL access', function () {
    $user = User::factory()->create();
    SellerProfile::factory()->draft()->create(['user_id' => $user->id]);

    $this->actingAs($user)->get(route('seller.dashboard'))->assertRedirect(route('seller.apply'));
    $this->actingAs($user)->get(route('seller.products.index'))->assertRedirect(route('seller.apply'));
    $this->actingAs($user)->get(route('seller.products.create'))->assertRedirect(route('seller.apply'));
    $this->actingAs($user)->get(route('seller.profile'))->assertRedirect(route('seller.apply'));
    $this->actingAs($user)->get(route('seller.settings'))->assertRedirect(route('seller.apply'));
    $this->actingAs($user)->get(route('seller.orders.index'))->assertRedirect(route('seller.apply'));
    $this->actingAs($user)->get(route('seller.payments.index'))->assertRedirect(route('seller.apply'));

    // Fee Calculator remains accessible
    $this->actingAs($user)->get(route('seller.fees.calculator'))->assertOk();
});

test('suspended seller can access orders and payments but is blocked from dashboard and products', function () {
    $user = User::factory()->create();
    SellerProfile::factory()->suspended()->create(['user_id' => $user->id]);

    // Allowed routes for suspended seller
    $this->actingAs($user)->get(route('seller.orders.index'))->assertOk();
    $this->actingAs($user)->get(route('seller.payments.index'))->assertOk();
    $this->actingAs($user)->get(route('seller.fees.calculator'))->assertOk();

    // Blocked routes for suspended seller
    $this->actingAs($user)->get(route('seller.dashboard'))->assertRedirect(route('seller.apply'));
    $this->actingAs($user)->get(route('seller.products.index'))->assertRedirect(route('seller.apply'));
    $this->actingAs($user)->get(route('seller.products.create'))->assertRedirect(route('seller.apply'));
    $this->actingAs($user)->get(route('seller.profile'))->assertRedirect(route('seller.apply'));
    $this->actingAs($user)->get(route('seller.settings'))->assertRedirect(route('seller.apply'));
});

test('suspended seller can ship an existing order', function () {
    Event::fake([SellerOrderShipped::class]);

    $category = Category::create(['name' => 'Toys', 'slug' => 'toys']);
    $vertical = Vertical::create([
        'category_id' => $category->id,
        'name' => 'Action Figures',
        'slug' => 'action-figures',
        'gst_rate' => 12.0,
        'hsn_code' => '950300',
    ]);
    $brand = Brand::create(['name' => 'Demo Brand', 'slug' => 'demo-brand', 'status' => 'approved']);

    $sellerUser = User::factory()->create(['email' => 'suspended-seller@example.com']);
    $seller = SellerProfile::factory()->approved()->create([
        'user_id' => $sellerUser->id,
        'store_name' => 'Suspended Store',
        'pickup_state' => 'Maharashtra',
        'pickup_postal_code' => '400001',
    ]);

    $customer = User::factory()->create(['email' => 'customer-fulfill@example.com']);
    $address = CustomerAddress::create([
        'user_id' => $customer->id,
        'name' => 'Customer Name',
        'phone' => '9876543210',
        'pincode' => '560001',
        'locality' => 'Koramangala',
        'address_line' => '123 Main St',
        'city' => 'Bengaluru',
        'state' => 'Karnataka',
        'type' => 'home',
        'is_default' => true,
    ]);

    $product = Product::factory()->approved()->create([
        'vertical_id' => $vertical->id,
        'brand_id' => $brand->id,
        'title' => 'Toy Robot',
        'slug' => 'toy-robot',
        'created_by' => $seller->id,
    ]);

    $variant = ProductVariant::create([
        'product_id' => $product->id,
        'upid' => 'VAR-SUSPEND-001',
        'sku' => 'SKU-SUSPEND-001',
        'weight' => 0.50,
        'qc_status' => 'approved',
    ]);

    $listing = Listing::factory()->active()->create([
        'product_variant_id' => $variant->id,
        'seller_profile_id' => $seller->id,
        'mrp' => 150000,
        'selling_price' => 100000,
        'stock' => 50,
    ]);

    $orderService = app(OrderService::class);
    $placeData = new PlaceOrderData(
        userId: $customer->id,
        addressId: $address->id,
        paymentMethod: 'cash_on_delivery',
        lines: [['listing_id' => $listing->id, 'qty' => 1]],
        expectedTotal: 100000,
        idempotencyKey: (string) Str::ulid(),
        source: 'cart',
    );
    $order = $orderService->place($placeData);
    $sellerOrder = SellerOrder::where('order_id', $order->id)->firstOrFail();

    // Now suspend the seller
    $seller->update(['status' => SellerStatus::Suspended]);

    // Move to Packed status so canShip() is true
    $sellerOrder->update(['status' => SellerOrderStatus::Packed]);

    // Suspended seller ships the order
    Livewire::actingAs($sellerUser, 'web')
        ->test('pages::seller.orders-show', ['sellerOrder' => $sellerOrder->id])
        ->set('courierName', 'Shiprocket - Blue Dart')
        ->set('trackingNumber', 'TRACK123456')
        ->call('confirmShip')
        ->assertHasNoErrors();

    $sellerOrder->refresh();
    expect($sellerOrder->status)->toBe(SellerOrderStatus::Shipped)
        ->and($sellerOrder->courier_name)->toBe('Shiprocket - Blue Dart')
        ->and($sellerOrder->tracking_number)->toBe('TRACK123456');

    Event::assertDispatched(SellerOrderShipped::class);
});

test('fee calculator works for user with no seller profile yet', function () {
    $schedule = FeeSchedule::create([
        'name' => 'Active Sched',
        'version' => 1,
        'status' => FeeScheduleStatus::Active,
        'effective_from' => now()->subDay(),
        'gst_rate' => 18.00,
        'tcs_rate' => 0.50,
        'tds_standard_rate' => 0.10,
        'tds_no_pan_rate' => 5.00,
        'default_weight_grams' => 500,
    ]);
    FeeCommissionSlab::create(['fee_schedule_id' => $schedule->id, 'min_price' => 0, 'max_price' => null, 'rate_bps' => 1000]);
    FeeClosingSlab::create(['fee_schedule_id' => $schedule->id, 'min_price' => 0, 'max_price' => null, 'fee_amount' => 2000]);
    FeeShippingSlab::create(['fee_schedule_id' => $schedule->id, 'min_weight_grams' => 0, 'max_weight_grams' => null, 'zone' => ShippingZone::National, 'fee_amount' => 5000, 'is_default' => true]);

    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::seller.fee-calculator')
        ->assertSet('hasAssumptions', true)
        ->assertSet('zone', 'national')
        ->assertSet('hasPan', true)
        ->assertHasNoErrors()
        ->assertSee('Preview Assumptions Applied')
        ->assertSee('National shipping zone')
        ->assertSee('Net Bank Payout');
});

test('rendered menu links in sidebar and dropdown return 200 and never redirect across all states', function ($stateFactory) {
    $schedule = FeeSchedule::create([
        'name' => 'Active Sched',
        'version' => 1,
        'status' => FeeScheduleStatus::Active,
        'effective_from' => now()->subDay(),
        'gst_rate' => 18.00,
        'tcs_rate' => 0.50,
        'tds_standard_rate' => 0.10,
        'tds_no_pan_rate' => 5.00,
        'default_weight_grams' => 500,
    ]);
    FeeCommissionSlab::create(['fee_schedule_id' => $schedule->id, 'min_price' => 0, 'max_price' => null, 'rate_bps' => 1000]);
    FeeClosingSlab::create(['fee_schedule_id' => $schedule->id, 'min_price' => 0, 'max_price' => null, 'fee_amount' => 2000]);
    FeeShippingSlab::create(['fee_schedule_id' => $schedule->id, 'min_weight_grams' => 0, 'max_weight_grams' => null, 'zone' => ShippingZone::National, 'fee_amount' => 5000, 'is_default' => true]);

    $user = User::factory()->create();
    $profile = $stateFactory($user);

    $extractHrefs = function (string $containerHtml): array {
        preg_match_all('/<a\b[^>]*href=["\']([^"\']+)["\']/i', $containerHtml, $matches);

        return array_unique($matches[1] ?? []);
    };

    // 1. Verify Customer Dashboard links
    $customerResponse = $this->actingAs($user)->get(route('dashboard'));
    $customerResponse->assertOk();
    $customerHtml = $customerResponse->getContent();

    preg_match('/<aside\b[^>]*data-test="customer-sidebar"[^>]*>(.*?)<\/aside>/is', $customerHtml, $cSidebarMatches);
    $cSidebarLinks = $extractHrefs($cSidebarMatches[1] ?? '');

    preg_match('/<div\b[^>]*data-test="customer-mobile-menu"[^>]*>(.*?)<\/div>\s*<\/div>/is', $customerHtml, $cMobileMatches);
    $cMobileLinks = $extractHrefs($cMobileMatches[1] ?? '');

    preg_match('/<div\b[^>]*data-test="account-menu-dropdown"[^>]*>(.*?)<\/div>\s*<\/div>/is', $customerHtml, $cDropdownMatches);
    $cDropdownLinks = $extractHrefs($cDropdownMatches[1] ?? '');

    preg_match('/<a\b[^>]*data-test="role-switch-button"[^>]*>/is', $customerHtml, $cSwitchTagMatch);
    preg_match('/href=["\']([^"\']+)["\']/i', $cSwitchTagMatch[0] ?? '', $cSwitchHrefMatch);
    $cSwitchLink = $cSwitchHrefMatch[1] ?? null;

    expect($cSidebarLinks)->not->toBeEmpty()
        ->and($cSwitchLink)->not->toBeNull();

    $allCustomerLinks = array_unique(array_filter(array_merge($cSidebarLinks, $cMobileLinks, $cDropdownLinks, [$cSwitchLink])));
    foreach ($allCustomerLinks as $url) {
        $linkResponse = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get($url);

        $linkResponse->assertOk();
    }

    // 2. Verify Seller Area links
    $sellerUrl = ($profile?->status === SellerStatus::Approved) ? route('seller.dashboard') : route('seller.fees.calculator');
    $sellerResponse = $this->actingAs($user)->get($sellerUrl);
    $sellerResponse->assertOk();
    $sellerHtml = $sellerResponse->getContent();

    preg_match('/<aside\b[^>]*data-test="seller-sidebar"[^>]*>(.*?)<\/aside>/is', $sellerHtml, $sSidebarMatches);
    $sSidebarLinks = $extractHrefs($sSidebarMatches[1] ?? '');

    preg_match('/<div\b[^>]*data-test="seller-mobile-menu"[^>]*>(.*?)<\/div>\s*<\/div>/is', $sellerHtml, $sMobileMatches);
    $sMobileLinks = $extractHrefs($sMobileMatches[1] ?? '');

    preg_match('/<div\b[^>]*data-test="account-menu-dropdown"[^>]*>(.*?)<\/div>\s*<\/div>/is', $sellerHtml, $sDropdownMatches);
    $sDropdownLinks = $extractHrefs($sDropdownMatches[1] ?? '');

    preg_match('/<a\b[^>]*data-test="role-switch-button"[^>]*>/is', $sellerHtml, $sSwitchTagMatch);
    preg_match('/href=["\']([^"\']+)["\']/i', $sSwitchTagMatch[0] ?? '', $sSwitchHrefMatch);
    $sSwitchLink = $sSwitchHrefMatch[1] ?? null;

    expect($sSidebarLinks)->not->toBeEmpty()
        ->and($sSwitchLink)->not->toBeNull();

    $allSellerLinks = array_unique(array_filter(array_merge($sSidebarLinks, $sMobileLinks, $sDropdownLinks, [$sSwitchLink])));
    foreach ($allSellerLinks as $url) {
        $linkResponse = $this->actingAs($user)
            ->withSession(['auth.password_confirmed_at' => time()])
            ->get($url);

        $linkResponse->assertOk();
    }
})->with([
    'no profile' => fn () => function (User $user) {
        return null;
    },
    'draft' => fn () => function (User $user) {
        return SellerProfile::factory()->draft()->create(['user_id' => $user->id]);
    },
    'pending' => fn () => function (User $user) {
        return SellerProfile::factory()->pending()->create(['user_id' => $user->id]);
    },
    'rejected' => fn () => function (User $user) {
        return SellerProfile::factory()->rejected()->create(['user_id' => $user->id]);
    },
    'suspended' => fn () => function (User $user) {
        return SellerProfile::factory()->suspended()->create(['user_id' => $user->id]);
    },
    'approved' => fn () => function (User $user) {
        return SellerProfile::factory()->approved()->create(['user_id' => $user->id]);
    },
]);

test('no seller page renders customer menu links and vice versa', function () {
    $schedule = FeeSchedule::create([
        'name' => 'Active Sched',
        'version' => 1,
        'status' => FeeScheduleStatus::Active,
        'effective_from' => now()->subDay(),
        'gst_rate' => 18.00,
        'tcs_rate' => 0.50,
        'tds_standard_rate' => 0.10,
        'tds_no_pan_rate' => 5.00,
        'default_weight_grams' => 500,
    ]);
    FeeCommissionSlab::create(['fee_schedule_id' => $schedule->id, 'min_price' => 0, 'max_price' => null, 'rate_bps' => 1000]);
    FeeClosingSlab::create(['fee_schedule_id' => $schedule->id, 'min_price' => 0, 'max_price' => null, 'fee_amount' => 2000]);
    FeeShippingSlab::create(['fee_schedule_id' => $schedule->id, 'min_weight_grams' => 0, 'max_weight_grams' => null, 'zone' => ShippingZone::National, 'fee_amount' => 5000, 'is_default' => true]);

    $sellerUser = User::factory()->create();
    SellerProfile::factory()->approved()->create(['user_id' => $sellerUser->id]);

    $customerUser = User::factory()->create();

    // Seller page (seller dashboard) must NOT render customer menu links
    $sellerResponse = $this->actingAs($sellerUser)->get(route('seller.dashboard'));
    $sellerResponse->assertOk()
        ->assertDontSee('href="'.route('orders.index').'"', false)
        ->assertDontSee('href="'.route('addresses.index').'"', false)
        ->assertDontSee('href="'.route('profile.edit').'"', false)
        ->assertDontSee('href="'.route('security.edit').'"', false)
        ->assertDontSee('href="'.route('appearance.edit').'"', false);

    // Customer page (customer dashboard) must NOT render seller store menu links
    $customerResponse = $this->actingAs($customerUser)->get(route('dashboard'));
    $customerResponse->assertOk()
        ->assertDontSee('href="'.route('seller.orders.index').'"', false)
        ->assertDontSee('href="'.route('seller.products.index').'"', false)
        ->assertDontSee('href="'.route('seller.products.create').'"', false)
        ->assertDontSee('href="'.route('seller.fees.calculator').'"', false)
        ->assertDontSee('href="'.route('seller.payments.index').'"', false)
        ->assertDontSee('href="'.route('seller.profile').'"', false)
        ->assertDontSee('href="'.route('seller.settings').'"', false);
});
