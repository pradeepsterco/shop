<?php

use App\Enums\SellerStatus;
use App\Helpdesk\Enums\RequesterType;
use App\Helpdesk\Enums\TicketCategory;
use App\Helpdesk\Enums\TicketStatus;
use App\Helpdesk\Models\SupportTicket;
use App\Helpdesk\Services\HelpdeskService;
use App\Mail\SupportTicketMail;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function helpdeskSeller(SellerStatus $status = SellerStatus::Approved): User
{
    $user = User::factory()->create();
    SellerProfile::factory()->create(['user_id' => $user->id, 'status' => $status, 'store_name' => 'Toy Kingdom '.$user->id]);

    return $user->fresh();
}

function helpdeskSellerOrder(User $seller, string $number = 'KH-ORD-SELL1'): SellerOrder
{
    $order = Order::create([
        'order_number' => $number,
        'user_id' => User::factory()->create()->id,
        'checkout_intent_id' => 'intent-'.$number,
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

    return SellerOrder::create([
        'order_id' => $order->id,
        'seller_profile_id' => $seller->sellerProfile->id,
        'seller_store_name' => $seller->sellerProfile->store_name,
        'status' => SellerOrderStatus::Placed,
        'subtotal' => 50000,
        'discount_total' => 0,
        'grand_total' => 50000,
        'placed_at' => now(),
    ]);
}

function openSellerHelpdeskTicket(User $seller, array $overrides = []): SupportTicket
{
    return app(HelpdeskService::class)->openSellerTicket(
        user: $seller,
        category: $overrides['category'] ?? TicketCategory::PayoutSettlement,
        subject: $overrides['subject'] ?? 'Payout for last week missing',
        body: $overrides['body'] ?? 'The payout batch for last week does not show my settlement.',
        sellerOrderId: $overrides['sellerOrderId'] ?? null,
    );
}

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
});

test('approved seller opens a ticket about one of their packages', function () {
    $seller = helpdeskSeller();
    $sellerOrder = helpdeskSellerOrder($seller);

    Livewire::actingAs($seller)
        ->withQueryParams(['order' => $sellerOrder->id])
        ->test('pages::seller.support-create')
        ->assertSet('category', TicketCategory::SellerOrders->value)
        ->set('subject', 'Courier did not pick up')
        ->set('body', 'The pickup was scheduled yesterday but nobody came.')
        ->set('attachments', [UploadedFile::fake()->image('manifest.png')])
        ->call('submit')
        ->assertHasNoErrors();

    $ticket = SupportTicket::query()->sole();
    expect($ticket->requester_type)->toBe(RequesterType::Seller)
        ->and($ticket->seller_profile_id)->toBe($seller->sellerProfile->id)
        ->and($ticket->requester_name)->toBe($seller->sellerProfile->store_name)
        ->and($ticket->seller_order_id)->toBe($sellerOrder->id)
        ->and($ticket->order_id)->toBe($sellerOrder->order_id)
        ->and($ticket->order_number)->toBe('KH-ORD-SELL1');

    Mail::assertQueued(SupportTicketMail::class, fn ($mail) => $mail->event === 'received'
        && str_contains($mail->render(), route('seller.support.show', ['ticket' => $ticket->ticket_number])));
});

test('seller cannot link another store\'s package or use a customer topic', function () {
    $seller = helpdeskSeller();
    $otherOrder = helpdeskSellerOrder(helpdeskSeller(), 'KH-ORD-OTHER');

    expect(fn () => openSellerHelpdeskTicket($seller, ['sellerOrderId' => $otherOrder->id]))
        ->toThrow(DomainException::class, 'We could not find that order in your store.');

    expect(fn () => openSellerHelpdeskTicket($seller, ['category' => TicketCategory::ProductQuestion]))
        ->toThrow(DomainException::class);

    expect(fn () => app(HelpdeskService::class)->openCustomerTicket(
        user: User::factory()->create(),
        category: TicketCategory::PayoutSettlement,
        subject: 'Not a customer topic',
        body: 'Customers cannot pick seller topics.',
    ))->toThrow(DomainException::class);

    expect(SupportTicket::count())->toBe(0);
});

test('a user without a seller profile cannot open a seller ticket', function () {
    expect(fn () => openSellerHelpdeskTicket(User::factory()->create()))
        ->toThrow(DomainException::class);
});

test('suspended sellers can reach support but pending applicants cannot', function () {
    $this->actingAs(helpdeskSeller(SellerStatus::Suspended))->get(route('seller.support.index'))->assertOk();
    $this->actingAs(helpdeskSeller(SellerStatus::Pending))->get(route('seller.support.index'))->assertRedirect();
});

test('seller and customer tickets stay in their own areas', function () {
    $seller = helpdeskSeller();
    $storeTicket = openSellerHelpdeskTicket($seller, ['subject' => 'Store payout question']);
    $shoppingTicket = app(HelpdeskService::class)->openCustomerTicket(
        user: $seller,
        category: TicketCategory::Delivery,
        subject: 'My own parcel is late',
        body: 'Something I bought as a shopper has not arrived.',
    );

    $this->actingAs($seller)->get(route('seller.support.index'))
        ->assertSee('Store payout question')
        ->assertDontSee('My own parcel is late');
    $this->actingAs($seller)->get(route('support.index'))
        ->assertSee('My own parcel is late')
        ->assertDontSee('Store payout question');

    $this->actingAs($seller)->get(route('seller.support.show', ['ticket' => $shoppingTicket->ticket_number]))->assertNotFound();
    $this->actingAs($seller)->get(route('support.show', ['ticket' => $storeTicket->ticket_number]))->assertNotFound();
});

test('a seller cannot open another seller\'s ticket', function () {
    $ticket = openSellerHelpdeskTicket(helpdeskSeller());

    $this->actingAs(helpdeskSeller())->get(route('seller.support.show', ['ticket' => $ticket->ticket_number]))->assertNotFound();
});

test('open-ticket limit counts seller and customer tickets separately', function () {
    config(['helpdesk.max_open_tickets' => 1]);
    $seller = helpdeskSeller();

    openSellerHelpdeskTicket($seller);
    $customerTicket = app(HelpdeskService::class)->openCustomerTicket(
        user: $seller,
        category: TicketCategory::Other,
        subject: 'Shopping question',
        body: 'A question from my shopping account.',
    );

    expect($customerTicket->exists)->toBeTrue()
        ->and(fn () => openSellerHelpdeskTicket($seller))->toThrow(DomainException::class);
});

test('staff reply reaches the seller, who can reply and close', function () {
    $seller = helpdeskSeller();
    $ticket = openSellerHelpdeskTicket($seller);
    app(HelpdeskService::class)->replyAsStaff($ticket, Admin::factory()->create(), 'The payout is in this Wednesday batch.');

    Livewire::actingAs($seller)
        ->test('pages::seller.support-show', ['ticket' => $ticket->ticket_number])
        ->assertSee('in this Wednesday batch')
        ->set('reply', 'Thanks, received it.')
        ->call('sendReply')
        ->assertHasNoErrors()
        ->call('closeTicket');

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed);
});

test('seller order page links to a new request for that package', function () {
    $seller = helpdeskSeller();
    $sellerOrder = helpdeskSellerOrder($seller);

    $this->actingAs($seller)->get(route('seller.orders.show', ['sellerOrder' => $sellerOrder->id]))
        ->assertOk()
        ->assertSee(route('seller.support.create', ['order' => $sellerOrder->id]), false);
});

test('admin inbox filters sellers and the ticket page links to the seller', function () {
    Artisan::call('permissions:sync');
    $admin = Admin::factory()->create(['is_active' => true]);
    $role = Role::findOrCreate('Support '.uniqid(), 'admin');
    $role->givePermissionTo([AdminPermissions::SUPPORT_VIEW, AdminPermissions::SELLERS_VIEW]);
    $admin->assignRole($role);

    $seller = helpdeskSeller();
    $ticket = openSellerHelpdeskTicket($seller, ['subject' => 'Store payout question']);
    app(HelpdeskService::class)->openCustomerTicket(
        user: User::factory()->create(),
        category: TicketCategory::Delivery,
        subject: 'Shopper parcel late',
        body: 'Something I bought has not arrived.',
    );

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.support-index')
        ->set('requester', 'seller')
        ->assertSee('Store payout question')
        ->assertDontSee('Shopper parcel late')
        ->set('requester', 'customer')
        ->assertSee('Shopper parcel late')
        ->assertDontSee('Store payout question');

    $this->actingAs($admin, 'admin')->get(route('admin.support.show', ['ticket' => $ticket->ticket_number]))
        ->assertOk()
        ->assertSee($seller->sellerProfile->store_name)
        ->assertSee(route('admin.sellers.show', ['sellerProfile' => $seller->sellerProfile->id]), false);
});
