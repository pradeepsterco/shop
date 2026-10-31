<?php

// Shared scenario for support relay and case-view tests.

use App\Enums\SellerStatus;
use App\Helpdesk\Enums\TicketCategory;
use App\Helpdesk\Models\SupportTicket;
use App\Helpdesk\Services\HelpdeskService;
use App\Models\Admin;
use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Support\AdminPermissions;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;

/**
 * A customer (Asha Verma) with an order from one seller (Toy Kingdom), and a customer ticket about it.
 *
 * @return array{customer: User, seller: User, sellerOrder: SellerOrder, ticket: SupportTicket, admin: Admin}
 */
function relayScenario(): array
{
    $customer = User::factory()->create(['name' => 'Asha Verma', 'email' => 'asha@example.com']);
    $seller = User::factory()->create(['email' => 'owner@toykingdom.example']);
    $profile = SellerProfile::factory()->create(['user_id' => $seller->id, 'status' => SellerStatus::Approved, 'store_name' => 'Toy Kingdom']);

    $order = Order::create([
        'order_number' => 'KH-ORD-RELAY',
        'user_id' => $customer->id,
        'checkout_intent_id' => 'intent-relay',
        'status' => OrderStatus::Placed,
        'delivery_address' => [],
        'delivery_state' => 'Karnataka',
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
        'seller_store_name' => 'Toy Kingdom',
        'status' => SellerOrderStatus::Delivered,
        'subtotal' => 50000,
        'discount_total' => 0,
        'grand_total' => 50000,
        'placed_at' => now(),
    ]);

    $ticket = app(HelpdeskService::class)->openCustomerTicket(
        user: $customer,
        category: TicketCategory::OrderIssue,
        subject: 'Wrong size received',
        body: 'I ordered size M but got size L.',
        orderNumber: 'KH-ORD-RELAY',
        attachments: [UploadedFile::fake()->image('asha_box_photo.jpg', 200, 200)],
    );

    $admin = Admin::factory()->create(['is_active' => true, 'name' => 'Ravi Agent']);
    $role = Role::findOrCreate('Relay '.uniqid(), 'admin');
    $role->givePermissionTo([AdminPermissions::SUPPORT_VIEW, AdminPermissions::SUPPORT_REPLY]);
    $admin->assignRole($role);

    return ['customer' => $customer, 'seller' => $seller->fresh(), 'sellerOrder' => $sellerOrder, 'ticket' => $ticket, 'admin' => $admin->fresh()];
}

function askRelaySeller(array $s, string $body = 'The customer reports size L instead of M. Do you have M in stock?', array $refs = []): SupportTicket
{
    return app(HelpdeskService::class)->askSeller(
        customerTicket: $s['ticket']->fresh(),
        admin: $s['admin'],
        sellerOrderId: $s['sellerOrder']->id,
        category: TicketCategory::SellerOrders,
        subject: 'Order KH-ORD-RELAY: wrong size',
        body: $body,
        attachmentRefs: $refs,
    );
}
