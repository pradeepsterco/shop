<?php

use App\Helpdesk\Enums\RequesterType;
use App\Helpdesk\Enums\TicketCategory;
use App\Helpdesk\Enums\TicketStatus;
use App\Helpdesk\Services\HelpdeskService;
use App\Models\Admin;
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

function unreadHelpdesk(): HelpdeskService
{
    return app(HelpdeskService::class);
}

test('a staff reply is unread for the customer until they open the ticket', function () {
    $s = relayScenario();
    expect(unreadHelpdesk()->unreadCountForRequester($s['customer']->id, RequesterType::Customer))->toBe(0);

    unreadHelpdesk()->replyAsStaff($s['ticket'], $s['admin'], 'Could you share a photo of the label?');
    expect(unreadHelpdesk()->unreadCountForRequester($s['customer']->id, RequesterType::Customer))->toBe(1);

    $this->actingAs($s['customer'], 'web')->get(route('support.index'))
        ->assertSeeHtml('data-test="support-unread-badge"')
        ->assertSeeHtml('data-test="ticket-new-reply"');

    $this->actingAs($s['customer'], 'web')->get(route('support.show', ['ticket' => $s['ticket']->ticket_number]))->assertOk();

    expect(unreadHelpdesk()->unreadCountForRequester($s['customer']->id, RequesterType::Customer))->toBe(0);
    $this->actingAs($s['customer'], 'web')->get(route('support.index'))
        ->assertDontSeeHtml('data-test="support-unread-badge"')
        ->assertDontSeeHtml('data-test="ticket-new-reply"');
});

test('internal notes never make a ticket unread for the customer', function () {
    $s = relayScenario();

    unreadHelpdesk()->replyAsStaff($s['ticket'], $s['admin'], 'Looks genuine', internal: true);

    expect($s['ticket']->fresh()->requester_unread)->toBeFalse();
});

test('resolving a ticket is unread news for the customer', function () {
    $s = relayScenario();

    unreadHelpdesk()->setStatus($s['ticket'], $s['admin'], TicketStatus::Resolved);

    expect($s['ticket']->fresh()->requester_unread)->toBeTrue();
});

test('the seller sees an unread badge when support asks them something', function () {
    $s = relayScenario();
    $sellerTicket = askRelaySeller($s);

    expect(unreadHelpdesk()->unreadCountForRequester($s['seller']->id, RequesterType::Seller))->toBe(1)
        // A seller who also shops keeps the two counts apart
        ->and(unreadHelpdesk()->unreadCountForRequester($s['seller']->id, RequesterType::Customer))->toBe(0);

    $this->actingAs($s['seller'], 'web')->get(route('seller.support.index'))
        ->assertSeeHtml('data-test="support-unread-badge"')
        ->assertSeeHtml('data-test="ticket-new-reply"');

    $this->actingAs($s['seller'], 'web')->get(route('seller.support.show', ['ticket' => $sellerTicket->ticket_number]))->assertOk();
    expect(unreadHelpdesk()->unreadCountForRequester($s['seller']->id, RequesterType::Seller))->toBe(0);
});

test('a new customer ticket is unread for each staff member until they open it', function () {
    $s = relayScenario();
    $other = Admin::factory()->create(['is_active' => true]);

    expect(unreadHelpdesk()->unreadCountForStaff($s['admin']->id))->toBe(1)
        ->and(unreadHelpdesk()->unreadCountForStaff($other->id))->toBe(1);

    Livewire::actingAs($s['admin'], 'admin')
        ->test('pages::admin.support-index')
        ->assertSeeHtml('data-test="ticket-unread"')
        ->assertSeeHtml('data-test="inbox-unread-count"');

    $this->actingAs($s['admin'], 'admin')->get(route('admin.support.show', ['ticket' => $s['ticket']->ticket_number]))->assertOk();

    // Read is per person: the colleague still has it unread
    expect(unreadHelpdesk()->unreadCountForStaff($s['admin']->id))->toBe(0)
        ->and(unreadHelpdesk()->unreadCountForStaff($other->id))->toBe(1);

    $this->travel(1)->minutes();
    unreadHelpdesk()->replyAsRequester($s['ticket']->fresh(), $s['customer'], 'Any update?');
    expect(unreadHelpdesk()->unreadCountForStaff($s['admin']->id))->toBe(1);
});

test('a seller reply makes the whole case unread for staff, counted once', function () {
    $s = relayScenario();
    $sellerTicket = askRelaySeller($s);
    $this->actingAs($s['admin'], 'admin')->get(route('admin.support.show', ['ticket' => $s['ticket']->ticket_number]))->assertOk();
    expect(unreadHelpdesk()->unreadCountForStaff($s['admin']->id))->toBe(0);

    $this->travel(1)->minutes();
    unreadHelpdesk()->replyAsRequester($sellerTicket, $s['seller'], 'M is out of stock.');
    expect(unreadHelpdesk()->unreadCountForStaff($s['admin']->id))->toBe(1);

    // Opening the case on either tab reads both sides
    $this->actingAs($s['admin'], 'admin')->get(route('admin.support.show', ['ticket' => $s['ticket']->ticket_number, 'side' => 'seller']))->assertOk();
    expect(unreadHelpdesk()->unreadCountForStaff($s['admin']->id))->toBe(0);
});

test('the staff badge only counts open tickets that are mine or unassigned', function () {
    $s = relayScenario();
    $colleague = Admin::factory()->create(['is_active' => true]);

    unreadHelpdesk()->assign($s['ticket'], $s['admin'], $colleague->id);
    expect(unreadHelpdesk()->unreadCountForStaff($s['admin']->id))->toBe(0)
        ->and(unreadHelpdesk()->unreadCountForStaff($colleague->id))->toBe(1);

    $this->actingAs($s['admin'], 'admin')->get(route('admin.dashboard'))->assertOk();
});

test('the customer sidebar badge only counts customer tickets', function () {
    $user = User::factory()->create();
    $ticket = unreadHelpdesk()->openCustomerTicket($user, TicketCategory::Other, 'A question here', 'Something about my account.');
    unreadHelpdesk()->replyAsStaff($ticket, Admin::factory()->create(), 'Here is the answer.');

    $this->actingAs($user, 'web')->get(route('orders.index'))
        ->assertSeeHtml('data-test="support-unread-badge"');
});
