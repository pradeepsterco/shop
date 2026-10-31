<?php

use App\Enums\SellerStatus;
use App\Helpdesk\Enums\TicketCategory;
use App\Helpdesk\Enums\TicketStatus;
use App\Helpdesk\Services\HelpdeskService;
use App\Mail\SupportTicketMail;
use App\Models\Admin;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// relayScenario() and askRelaySeller()
require_once __DIR__.'/../../Support/HelpdeskScenarios.php';

beforeEach(function () {
    Artisan::call('permissions:sync');
    Mail::fake();
    Storage::fake('local');
});

test('a resolved ticket asks the customer whether it is solved instead of offering a reply box', function () {
    $s = relayScenario();
    app(HelpdeskService::class)->setStatus($s['ticket'], $s['admin'], TicketStatus::Resolved);

    $this->actingAs($s['customer'], 'web')->get(route('support.show', ['ticket' => $s['ticket']->ticket_number]))
        ->assertOk()
        ->assertSeeHtml('data-test="resolved-choice"')
        ->assertSee('Yes, it is solved')
        ->assertSee('No, I still need help')
        ->assertSee('it closes on')
        ->assertDontSee('Send reply');
});

test('"Yes, it is solved" closes the ticket', function () {
    $s = relayScenario();
    app(HelpdeskService::class)->setStatus($s['ticket'], $s['admin'], TicketStatus::Resolved);

    Livewire::actingAs($s['customer'])
        ->test('pages::storefront.support-show', ['ticket' => $s['ticket']->ticket_number])
        ->call('closeTicket')
        ->assertSeeHtml('data-test="ticket-closed"');

    $ticket = $s['ticket']->fresh();
    expect($ticket->status)->toBe(TicketStatus::Closed)
        ->and($ticket->messages()->where('body', 'The customer confirmed it is solved.')->exists())->toBeTrue();
});

test('"No, I still need help" needs a message and reopens the ticket', function () {
    $s = relayScenario();
    app(HelpdeskService::class)->setStatus($s['ticket'], $s['admin'], TicketStatus::Resolved);

    Livewire::actingAs($s['customer'])
        ->test('pages::storefront.support-show', ['ticket' => $s['ticket']->ticket_number])
        ->set('reopening', true)
        ->assertSee('Reopen request')
        ->call('sendReply')
        ->assertHasErrors('reply')
        ->set('reply', 'The replacement is also the wrong size.')
        ->call('sendReply')
        ->assertHasNoErrors()
        ->assertSet('reopening', false);

    expect($s['ticket']->fresh()->status)->toBe(TicketStatus::Open);
});

test('a seller resolving their own ticket gets the same choice', function () {
    $seller = User::factory()->create();
    SellerProfile::factory()->create(['user_id' => $seller->id, 'status' => SellerStatus::Approved]);
    $ticket = app(HelpdeskService::class)->openSellerTicket($seller->fresh(), TicketCategory::PayoutSettlement, 'Payout missing', 'My payout for last week has not arrived.');
    app(HelpdeskService::class)->setStatus($ticket, Admin::factory()->create(), TicketStatus::Resolved);

    $this->actingAs($seller, 'web')->get(route('seller.support.show', ['ticket' => $ticket->ticket_number]))
        ->assertOk()
        ->assertSeeHtml('data-test="resolved-choice"');
});

test('a customer closing their case closes the seller side', function () {
    $s = relayScenario();
    $sellerTicket = askRelaySeller($s);

    app(HelpdeskService::class)->closeByRequester($s['ticket']->fresh(), $s['customer']);

    expect($sellerTicket->fresh()->status)->toBe(TicketStatus::Closed);
});

test('a seller closing their side of a case flags the case for staff', function () {
    $s = relayScenario();
    $sellerTicket = askRelaySeller($s);
    $this->actingAs($s['admin'], 'admin')->get(route('admin.support.show', ['ticket' => $s['ticket']->ticket_number]))->assertOk();

    $this->travel(1)->minutes();
    app(HelpdeskService::class)->closeByRequester($sellerTicket->fresh(), $s['seller']);

    expect(app(HelpdeskService::class)->unreadCountForStaff($s['admin']->id))->toBe(1)
        ->and($s['ticket']->messages()->where('body', "The seller closed {$sellerTicket->ticket_number}.")->where('is_internal', true)->exists())->toBeTrue()
        // The customer's case is still open with staff
        ->and($s['ticket']->fresh()->status)->toBe(TicketStatus::AwaitingSeller);
});

test('tickets waiting on the customer for 7 days are resolved, then close 7 days later', function () {
    $s = relayScenario();
    app(HelpdeskService::class)->replyAsStaff($s['ticket'], $s['admin'], 'Could you send a photo of the label?');

    $this->travel(6)->days();
    $this->artisan('helpdesk:resolve-unanswered')->assertSuccessful();
    expect($s['ticket']->fresh()->status)->toBe(TicketStatus::Pending);

    $this->travel(2)->days();
    $this->artisan('helpdesk:resolve-unanswered')->assertSuccessful();
    $ticket = $s['ticket']->fresh();
    expect($ticket->status)->toBe(TicketStatus::Resolved)
        ->and($ticket->requester_unread)->toBeTrue();
    Mail::assertQueued(SupportTicketMail::class, fn ($mail) => $mail->event === 'resolved' && $mail->hasTo('asha@example.com'));

    $this->travel(8)->days();
    $this->artisan('helpdesk:close-resolved')->assertSuccessful();
    expect($s['ticket']->fresh()->status)->toBe(TicketStatus::Closed);
});

test('seller sides of cases are not auto-resolved; their reply deadline applies instead', function () {
    $s = relayScenario();
    $sellerTicket = askRelaySeller($s);

    $this->travel(10)->days();
    $this->artisan('helpdesk:resolve-unanswered')->assertSuccessful();

    expect($sellerTicket->fresh()->status)->toBe(TicketStatus::Pending)
        ->and($sellerTicket->fresh()->isSellerOverdue())->toBeTrue();
});
