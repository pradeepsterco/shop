<?php

use App\Helpdesk\Services\GrievanceOfficerService;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Artisan::call('permissions:sync');
});

function grievanceAdmin(array $permissions): Admin
{
    $admin = Admin::factory()->create(['is_active' => true]);
    $role = Role::findOrCreate('Grievance '.uniqid(), 'admin');
    $role->givePermissionTo($permissions);
    $admin->assignRole($role);

    return $admin->fresh();
}

test('public grievance page says details are coming until configured', function () {
    $this->get(route('grievance-officer'))
        ->assertOk()
        ->assertSee('will be published here shortly')
        ->assertSee('48 hours')
        ->assertSee('one month');
});

test('admin with support.settings saves the grievance officer and the public page shows them', function () {
    $admin = grievanceAdmin([AdminPermissions::SUPPORT_SETTINGS]);

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.support-settings')
        ->set('grievance_name', 'Priya Nair')
        ->set('grievance_designation', 'Grievance Officer')
        ->set('grievance_email', 'grievance@example.com')
        ->set('grievance_phone', '+91 80 1234 5678')
        ->set('grievance_address', "2nd Floor, MG Road\nBengaluru 560001")
        ->call('save')
        ->assertHasNoErrors();

    expect(app(GrievanceOfficerService::class)->isConfigured())->toBeTrue()
        ->and(AdminActivityLog::query()->where('action', 'support.grievance_officer_updated')->exists())->toBeTrue();

    $this->get(route('grievance-officer'))
        ->assertOk()
        ->assertSee('Priya Nair')
        ->assertSee('grievance@example.com')
        ->assertSee('Bengaluru 560001');
});

test('grievance officer details are validated', function () {
    Livewire::actingAs(grievanceAdmin([AdminPermissions::SUPPORT_SETTINGS]), 'admin')
        ->test('pages::admin.support-settings')
        ->set('grievance_name', '')
        ->set('grievance_email', 'not-an-email')
        ->set('grievance_phone', 'call me')
        ->call('save')
        ->assertHasErrors(['grievance_name', 'grievance_designation', 'grievance_email', 'grievance_phone', 'grievance_address']);
});

test('settings page needs support.settings', function () {
    $this->actingAs(grievanceAdmin([AdminPermissions::SUPPORT_VIEW]), 'admin')
        ->get(route('admin.support.settings'))
        ->assertForbidden();
});

test('storefront footer links to the grievance officer', function () {
    $this->get(route('grievance-officer'))->assertSee(route('grievance-officer'), false);
});

test('seller order page does not show the customer phone numbers', function () {
    $sellerUser = User::factory()->create();
    $profile = SellerProfile::factory()->approved()->create(['user_id' => $sellerUser->id]);
    $order = Order::create([
        'order_number' => 'KH-ORD-PHONE',
        'user_id' => User::factory()->create()->id,
        'checkout_intent_id' => 'intent-phone',
        'status' => OrderStatus::Placed,
        'delivery_address' => [
            'name' => 'Asha Verma',
            'phone' => '9876543210',
            'alternate_phone' => '9123456780',
            'address_line' => '14 Lake View Road',
            'city' => 'Pune',
            'pincode' => '411001',
        ],
        'delivery_state' => 'Maharashtra',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Pending,
        'items_mrp_total' => 50000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 50000,
        'placed_at' => now(),
    ]);
    $sellerOrder = SellerOrder::create([
        'order_id' => $order->id,
        'seller_profile_id' => $profile->id,
        'seller_store_name' => $profile->store_name,
        'status' => SellerOrderStatus::Placed,
        'subtotal' => 50000,
        'discount_total' => 0,
        'grand_total' => 50000,
        'placed_at' => now(),
    ]);

    $this->actingAs($sellerUser)->get(route('seller.orders.show', ['sellerOrder' => $sellerOrder->id]))
        ->assertOk()
        ->assertSee('Asha Verma')
        ->assertSee('14 Lake View Road')
        ->assertDontSee('9876543210')
        ->assertDontSee('9123456780');
});
