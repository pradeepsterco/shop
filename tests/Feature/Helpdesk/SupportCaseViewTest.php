<?php

use App\Helpdesk\Enums\TicketCategory;
use App\Helpdesk\Enums\TicketStatus;
use App\Helpdesk\Services\HelpdeskService;
use App\Models\Admin;
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

test('a customer ticket and its seller side are one inbox row, counted once', function () {
    $s = relayScenario();
    $sellerTicket = askRelaySeller($s);
    app(HelpdeskService::class)->replyAsRequester($sellerTicket, $s['seller'], 'M is out of stock.');

    // Both tickets are open now, but they are one case
    expect($s['ticket']->fresh()->status)->toBe(TicketStatus::Open)
        ->and($sellerTicket->fresh()->status)->toBe(TicketStatus::Open);

    Livewire::actingAs($s['admin'], 'admin')
        ->test('pages::admin.support-index')
        ->assertSet('status', 'open')
        ->assertSeeHtml('data-test="case-seller-line"')
        ->assertSee('Toy Kingdom')
        ->assertSee('Needs reply: 1')
        ->assertDontSee('Order KH-ORD-RELAY: wrong size'); // the seller ticket's own subject never gets a row
});

test('searching the seller ticket number or store name finds the case', function () {
    $s = relayScenario();
    $sellerTicket = askRelaySeller($s);

    Livewire::actingAs($s['admin'], 'admin')
        ->test('pages::admin.support-index')
        ->set('status', 'all')
        ->set('search', $sellerTicket->ticket_number)
        ->assertSee('Wrong size received')
        ->set('search', 'Toy Kingdom')
        ->assertSee('Wrong size received');
});

test('seller tickets the seller opened themselves keep their own row', function () {
    $s = relayScenario();
    app(HelpdeskService::class)->openSellerTicket($s['seller'], TicketCategory::PayoutSettlement, 'Payout missing this week', 'My payout for last week has not arrived.');

    Livewire::actingAs($s['admin'], 'admin')
        ->test('pages::admin.support-index')
        ->assertSee('Payout missing this week')
        ->assertSee('Wrong size received');
});

test('opening the seller side redirects to the case on the seller tab', function () {
    $s = relayScenario();
    $sellerTicket = askRelaySeller($s);

    $this->actingAs($s['admin'], 'admin')
        ->get(route('admin.support.show', ['ticket' => $sellerTicket->ticket_number]))
        ->assertRedirect(route('admin.support.show', ['ticket' => $s['ticket']->ticket_number, 'side' => 'seller']));

    $this->actingAs($s['admin'], 'admin')
        ->get(route('admin.support.show', ['ticket' => $s['ticket']->ticket_number, 'side' => 'seller']))
        ->assertOk()
        ->assertSee('data-test="case-tabs"', false)
        ->assertSee('Wrong size received') // case header
        ->assertSee('The customer reports size L instead of M'); // seller conversation
});

test('the tabs switch conversations and replies go to the open side', function () {
    $s = relayScenario();
    $sellerTicket = askRelaySeller($s);

    Livewire::actingAs($s['admin'], 'admin')
        ->test('pages::admin.support-show', ['ticket' => $s['ticket']->ticket_number])
        ->assertSee('I ordered size M but got size L.')
        ->call('switchSide', 'seller')
        ->assertSet('side', 'seller')
        ->assertSee('The customer reports size L instead of M')
        ->set('reply', 'Please also confirm the dispatch date.')
        ->call('sendReply')
        ->assertHasNoErrors();

    expect($sellerTicket->messages()->where('body', 'Please also confirm the dispatch date.')->exists())->toBeTrue()
        ->and($s['ticket']->messages()->where('body', 'Please also confirm the dispatch date.')->exists())->toBeFalse();
});

test('resolving the customer ticket closes the seller side, which the seller cannot reopen', function () {
    $s = relayScenario();
    $sellerTicket = askRelaySeller($s);

    app(HelpdeskService::class)->setStatus($s['ticket']->fresh(), $s['admin'], TicketStatus::Resolved);

    expect($s['ticket']->fresh()->status)->toBe(TicketStatus::Resolved)
        ->and($sellerTicket->fresh()->status)->toBe(TicketStatus::Closed)
        ->and($sellerTicket->fresh()->reply_due_at)->toBeNull()
        ->and($sellerTicket->fresh()->requester_unread)->toBeTrue();

    expect(fn () => app(HelpdeskService::class)->replyAsRequester($sellerTicket->fresh(), $s['seller'], 'One more thing', reopen: true))
        ->toThrow(DomainException::class);
});

test('assigning the case assigns both sides', function () {
    $s = relayScenario();
    $sellerTicket = askRelaySeller($s);
    $lead = Admin::factory()->create(['is_active' => true, 'name' => 'Lead Agent']);

    app(HelpdeskService::class)->assign($s['ticket']->fresh(), $s['admin'], $lead->id);

    expect($s['ticket']->fresh()->assigned_admin_id)->toBe($lead->id)
        ->and($sellerTicket->fresh()->assigned_admin_id)->toBe($lead->id);
});

test('"Answered" is only for seller tickets', function () {
    $s = relayScenario();

    expect(fn () => app(HelpdeskService::class)->setStatus($s['ticket']->fresh(), $s['admin'], TicketStatus::Answered))
        ->toThrow(DomainException::class);
});
