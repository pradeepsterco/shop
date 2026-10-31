<?php

use App\Helpdesk\Enums\MessageAuthor;
use App\Helpdesk\Enums\TicketCategory;
use App\Helpdesk\Enums\TicketPriority;
use App\Helpdesk\Enums\TicketStatus;
use App\Helpdesk\Models\SupportTicket;
use App\Helpdesk\Services\HelpdeskService;
use App\Mail\SupportTicketMail;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Models\User;
use App\Support\AdminPermissions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function supportStaff(array $permissions): Admin
{
    $admin = Admin::factory()->create(['is_active' => true]);
    $role = Role::findOrCreate('Support '.uniqid(), 'admin');
    $role->givePermissionTo($permissions);
    $admin->assignRole($role);

    return $admin->fresh();
}

function customerTicket(string $subject = 'Parcel not delivered'): SupportTicket
{
    return app(HelpdeskService::class)->openCustomerTicket(
        user: User::factory()->create(),
        category: TicketCategory::Delivery,
        subject: $subject,
        body: 'The tracking says delivered but nothing arrived.',
    );
}

beforeEach(function () {
    Artisan::call('permissions:sync');
    Mail::fake();
    Storage::fake('local');
});

test('inbox needs support.view', function () {
    $this->get(route('admin.support.index'))->assertRedirect(route('admin.login'));

    $this->actingAs(supportStaff([]), 'admin')->get(route('admin.support.index'))->assertForbidden();
    $this->actingAs(supportStaff([AdminPermissions::SUPPORT_VIEW]), 'admin')->get(route('admin.support.index'))->assertOk();
});

test('inbox lists open tickets and filters by assignee and search', function () {
    $viewer = supportStaff([AdminPermissions::SUPPORT_VIEW]);
    $first = customerTicket('Parcel not delivered');
    $second = customerTicket('Refund still pending');
    $second->update(['assigned_admin_id' => $viewer->id]);

    Livewire::actingAs($viewer, 'admin')
        ->test('pages::admin.support-index')
        ->assertSee('Parcel not delivered')
        ->assertSee('Refund still pending')
        ->set('assignee', 'mine')
        ->assertDontSee('Parcel not delivered')
        ->assertSee('Refund still pending')
        ->set('assignee', 'all')
        ->set('search', $first->ticket_number)
        ->assertSee('Parcel not delivered')
        ->assertDontSee('Refund still pending');
});

test('viewer without support.reply cannot reply or change status', function () {
    $viewer = supportStaff([AdminPermissions::SUPPORT_VIEW]);
    $ticket = customerTicket();

    Livewire::actingAs($viewer, 'admin')
        ->test('pages::admin.support-show', ['ticket' => $ticket->ticket_number])
        ->assertOk()
        ->set('reply', 'Hello')
        ->call('sendReply')
        ->assertForbidden();

    Livewire::actingAs($viewer, 'admin')
        ->test('pages::admin.support-show', ['ticket' => $ticket->ticket_number])
        ->call('updateStatus', 'resolved')
        ->assertForbidden();

    expect($ticket->refresh()->status)->toBe(TicketStatus::Open);
});

test('staff reply emails the customer, waits on them and takes the ticket', function () {
    $agent = supportStaff([AdminPermissions::SUPPORT_VIEW, AdminPermissions::SUPPORT_REPLY]);
    $ticket = customerTicket();

    Livewire::actingAs($agent, 'admin')
        ->test('pages::admin.support-show', ['ticket' => $ticket->ticket_number])
        ->set('reply', 'We have raised this with the courier.')
        ->call('sendReply')
        ->assertHasNoErrors();

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Pending)
        ->and($ticket->assigned_admin_id)->toBe($agent->id)
        ->and($ticket->first_response_at)->not->toBeNull();

    Mail::assertQueued(SupportTicketMail::class, fn ($mail) => $mail->event === 'staff_reply' && $mail->hasTo($ticket->requester_email));
    expect(AdminActivityLog::query()->where('action', 'support.replied')->where('admin_id', $agent->id)->exists())->toBeTrue();
});

test('internal note does not change status or email the customer', function () {
    $agent = supportStaff([AdminPermissions::SUPPORT_VIEW, AdminPermissions::SUPPORT_REPLY]);
    $ticket = customerTicket();

    Livewire::actingAs($agent, 'admin')
        ->test('pages::admin.support-show', ['ticket' => $ticket->ticket_number])
        ->set('internal', true)
        ->set('reply', 'Courier claim filed, waiting on them.')
        ->call('sendReply')
        ->assertHasNoErrors()
        ->assertSee('Courier claim filed');

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Open)
        ->and($ticket->first_response_at)->toBeNull()
        ->and($ticket->messages()->where('is_internal', true)->count())->toBe(1);

    Mail::assertNotQueued(SupportTicketMail::class, fn ($mail) => $mail->event === 'staff_reply');
});

test('resolving emails the customer and closed tickets refuse public replies', function () {
    $agent = supportStaff([AdminPermissions::SUPPORT_VIEW, AdminPermissions::SUPPORT_REPLY]);
    $ticket = customerTicket();

    Livewire::actingAs($agent, 'admin')
        ->test('pages::admin.support-show', ['ticket' => $ticket->ticket_number])
        ->call('updateStatus', 'resolved')
        ->call('updatePriority', 'urgent');

    $ticket->refresh();
    expect($ticket->status)->toBe(TicketStatus::Resolved)
        ->and($ticket->priority)->toBe(TicketPriority::Urgent);
    Mail::assertQueued(SupportTicketMail::class, fn ($mail) => $mail->event === 'resolved');

    app(HelpdeskService::class)->setStatus($ticket, $agent, TicketStatus::Closed);

    Livewire::actingAs($agent, 'admin')
        ->test('pages::admin.support-show', ['ticket' => $ticket->ticket_number])
        ->set('reply', 'One more update')
        ->call('sendReply')
        ->assertHasErrors('reply');
});

test('assigning needs support.assign; repliers can only take unassigned tickets', function () {
    $agent = supportStaff([AdminPermissions::SUPPORT_VIEW, AdminPermissions::SUPPORT_REPLY]);
    $lead = supportStaff([AdminPermissions::SUPPORT_VIEW, AdminPermissions::SUPPORT_REPLY, AdminPermissions::SUPPORT_ASSIGN]);
    $ticket = customerTicket();

    Livewire::actingAs($agent, 'admin')
        ->test('pages::admin.support-show', ['ticket' => $ticket->ticket_number])
        ->call('assignTo', (string) $lead->id)
        ->assertForbidden();

    Livewire::actingAs($agent, 'admin')
        ->test('pages::admin.support-show', ['ticket' => $ticket->ticket_number])
        ->call('takeTicket');
    expect($ticket->refresh()->assigned_admin_id)->toBe($agent->id);

    Livewire::actingAs($lead, 'admin')
        ->test('pages::admin.support-show', ['ticket' => $ticket->ticket_number])
        ->call('assignTo', (string) $lead->id);
    expect($ticket->refresh()->assigned_admin_id)->toBe($lead->id);

    $note = $ticket->messages()->where('author_type', MessageAuthor::System)->reorder('id', 'desc')->first();
    expect($note->is_internal)->toBeTrue()
        ->and($note->body)->toContain($lead->name);
});

test('admin attachment route needs support.view', function () {
    $ticket = app(HelpdeskService::class)->openCustomerTicket(
        user: User::factory()->create(),
        category: TicketCategory::Other,
        subject: 'Invoice question',
        body: 'Please see the attached invoice copy.',
        attachments: [UploadedFile::fake()->create('invoice.pdf', 20, 'application/pdf')],
    );
    $url = route('admin.support.attachment', ['ticket' => $ticket->ticket_number, 'message' => $ticket->messages()->sole()->id, 'index' => 0]);

    $this->actingAs(supportStaff([]), 'admin')->get($url)->assertForbidden();
    $this->actingAs(supportStaff([AdminPermissions::SUPPORT_VIEW]), 'admin')->get($url)->assertOk();
});
