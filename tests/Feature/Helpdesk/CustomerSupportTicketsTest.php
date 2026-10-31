<?php

use App\Helpdesk\Enums\MessageAuthor;
use App\Helpdesk\Enums\TicketCategory;
use App\Helpdesk\Enums\TicketStatus;
use App\Helpdesk\Models\SupportTicket;
use App\Helpdesk\Services\HelpdeskService;
use App\Mail\SupportTicketMail;
use App\Models\Admin;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Models\Order;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

function helpdeskOrderFor(User $user, string $number = 'KH-ORD-HELP1'): Order
{
    return Order::create([
        'order_number' => $number,
        'user_id' => $user->id,
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
}

function openHelpdeskTicket(User $user, array $overrides = []): SupportTicket
{
    return app(HelpdeskService::class)->openCustomerTicket(
        user: $user,
        category: $overrides['category'] ?? TicketCategory::OrderIssue,
        subject: $overrides['subject'] ?? 'Wrong size received',
        body: $overrides['body'] ?? 'I ordered size M but got size L in the box.',
        orderNumber: $overrides['orderNumber'] ?? null,
        attachments: $overrides['attachments'] ?? [],
    );
}

beforeEach(function () {
    Mail::fake();
    Storage::fake('local');
});

test('guests are sent to login from the support pages', function () {
    $this->get(route('support.index'))->assertRedirect(route('login'));
    $this->get(route('support.create'))->assertRedirect(route('login'));
});

test('customer opens a ticket linked to their own order with an attachment', function () {
    $user = User::factory()->create();
    $order = helpdeskOrderFor($user);

    Livewire::actingAs($user)
        ->withQueryParams(['order' => $order->order_number])
        ->test('pages::storefront.support-create')
        ->assertSet('category', TicketCategory::OrderIssue->value)
        ->set('subject', 'Wrong size received')
        ->set('body', 'I ordered size M but got size L in the box.')
        ->set('attachments', [UploadedFile::fake()->image('box.jpg')])
        ->call('submit')
        ->assertHasNoErrors();

    $ticket = SupportTicket::query()->sole();
    expect($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->order_id)->toBe($order->id)
        ->and($ticket->order_number)->toBe('KH-ORD-HELP1')
        ->and($ticket->requester_email)->toBe($user->email)
        ->and($ticket->ticket_number)->toStartWith('SUP-');

    $message = $ticket->messages()->sole();
    expect($message->author_type)->toBe(MessageAuthor::Requester)
        ->and($message->attachments)->toHaveCount(1);
    Storage::disk('local')->assertExists($message->attachments[0]['path']);

    Mail::assertQueued(SupportTicketMail::class, fn ($mail) => $mail->event === 'received' && $mail->hasTo($user->email));
});

test('customer cannot link someone else\'s order', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $otherOrder = helpdeskOrderFor($other, 'KH-ORD-OTHER');

    expect(fn () => openHelpdeskTicket($user, ['orderNumber' => $otherOrder->order_number]))
        ->toThrow(DomainException::class, 'We could not find that order on your account.');

    expect(SupportTicket::count())->toBe(0);
});

test('subject and message lengths are validated', function () {
    $user = User::factory()->create();

    expect(fn () => openHelpdeskTicket($user, ['subject' => 'Hi', 'body' => 'short']))
        ->toThrow(ValidationException::class);
});

test('attachments must be images or PDFs and at most three', function () {
    $user = User::factory()->create();

    expect(fn () => openHelpdeskTicket($user, ['attachments' => [UploadedFile::fake()->create('virus.exe', 10)]]))
        ->toThrow(ValidationException::class);

    expect(fn () => openHelpdeskTicket($user, ['attachments' => array_fill(0, 4, UploadedFile::fake()->image('a.jpg'))]))
        ->toThrow(ValidationException::class);

    expect(SupportTicket::count())->toBe(0);
});

test('customer cannot have more than the allowed number of open tickets', function () {
    config(['helpdesk.max_open_tickets' => 2]);
    $user = User::factory()->create();

    openHelpdeskTicket($user);
    openHelpdeskTicket($user);

    expect(fn () => openHelpdeskTicket($user))->toThrow(DomainException::class);
});

test('customer sees only their own tickets', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $mine = openHelpdeskTicket($user, ['subject' => 'My own request']);
    $theirs = openHelpdeskTicket($other, ['subject' => 'Someone else request']);

    $this->actingAs($user)->get(route('support.index'))
        ->assertOk()
        ->assertSee('My own request')
        ->assertDontSee('Someone else request');

    $this->actingAs($user)->get(route('support.show', ['ticket' => $mine->ticket_number]))->assertOk();
    $this->actingAs($user)->get(route('support.show', ['ticket' => $theirs->ticket_number]))->assertNotFound();
});

test('customer never sees internal notes or their attachments', function () {
    $user = User::factory()->create();
    $ticket = openHelpdeskTicket($user);
    $admin = Admin::factory()->create();

    app(HelpdeskService::class)->replyAsStaff($ticket, $admin, 'Seller is known for wrong sizes', [UploadedFile::fake()->image('note.jpg')], internal: true);
    app(HelpdeskService::class)->replyAsStaff($ticket, $admin, 'Sorry about that, we are arranging a replacement.');

    $this->actingAs($user)->get(route('support.show', ['ticket' => $ticket->ticket_number]))
        ->assertOk()
        ->assertSee('arranging a replacement')
        ->assertDontSee('Seller is known for wrong sizes');

    $note = $ticket->messages()->where('is_internal', true)->sole();
    $this->actingAs($user)
        ->get(route('support.attachment', ['ticket' => $ticket->ticket_number, 'message' => $note->id, 'index' => 0]))
        ->assertNotFound();
});

test('customer can download their own attachment but not another customer\'s', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $ticket = openHelpdeskTicket($user, ['attachments' => [UploadedFile::fake()->image('box.jpg')]]);
    $message = $ticket->messages()->sole();
    $url = route('support.attachment', ['ticket' => $ticket->ticket_number, 'message' => $message->id, 'index' => 0]);

    $this->actingAs($user)->get($url)->assertOk();
    $this->actingAs($other)->get($url)->assertNotFound();
});

test('customer reply puts a pending ticket back with staff; a resolved one only reopens on purpose', function () {
    $user = User::factory()->create();
    $ticket = openHelpdeskTicket($user);
    $admin = Admin::factory()->create();
    $helpdesk = app(HelpdeskService::class);

    $helpdesk->replyAsStaff($ticket, $admin, 'Could you share a photo of the label?');
    expect($ticket->refresh()->status)->toBe(TicketStatus::Pending);

    Livewire::actingAs($user)
        ->test('pages::storefront.support-show', ['ticket' => $ticket->ticket_number])
        ->set('reply', 'Here it is.')
        ->set('attachments', [UploadedFile::fake()->image('label.png')])
        ->call('sendReply')
        ->assertHasNoErrors();

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);

    $helpdesk->setStatus($ticket, $admin, TicketStatus::Resolved);

    // A plain reply ("thanks") does not reopen a resolved ticket
    expect(fn () => $helpdesk->replyAsRequester($ticket->refresh(), $user, 'Thanks!'))
        ->toThrow(DomainException::class);
    expect($ticket->refresh()->status)->toBe(TicketStatus::Resolved);

    $helpdesk->replyAsRequester($ticket->refresh(), $user, 'Actually it is still wrong.', reopen: true);

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open)
        ->and($ticket->resolved_at)->toBeNull()
        ->and($ticket->messages()->where('body', 'Reopened by the customer.')->exists())->toBeTrue();
});

test('customer can close a ticket and then cannot reply', function () {
    $user = User::factory()->create();
    $ticket = openHelpdeskTicket($user);

    Livewire::actingAs($user)
        ->test('pages::storefront.support-show', ['ticket' => $ticket->ticket_number])
        ->call('closeTicket')
        ->assertSee('This request is closed.');

    expect($ticket->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($ticket->closed_at)->not->toBeNull();

    expect(fn () => app(HelpdeskService::class)->replyAsRequester($ticket, $user, 'One more thing'))
        ->toThrow(DomainException::class);
});

test('order page links to a new request for that order', function () {
    $user = User::factory()->create();
    $order = helpdeskOrderFor($user);

    $this->actingAs($user)->get(route('orders.show', ['order' => $order->order_number]))
        ->assertOk()
        ->assertSee(route('support.create', ['order' => $order->order_number]), false);
});

test('resolved tickets close automatically after the configured days', function () {
    $user = User::factory()->create();
    $admin = Admin::factory()->create();
    $stale = openHelpdeskTicket($user);
    $fresh = openHelpdeskTicket($user);
    $helpdesk = app(HelpdeskService::class);

    $this->travelTo(now()->subDays(8), function () use ($helpdesk, $stale, $admin) {
        $helpdesk->replyAsStaff($stale, $admin, 'Replacement shipped.', statusAfter: TicketStatus::Resolved);
    });
    $helpdesk->replyAsStaff($fresh, $admin, 'Refund issued.', statusAfter: TicketStatus::Resolved);

    $this->artisan('helpdesk:close-resolved')->assertSuccessful();

    expect($stale->refresh()->status)->toBe(TicketStatus::Closed)
        ->and($fresh->refresh()->status)->toBe(TicketStatus::Resolved);
});
