<?php

use App\Helpdesk\Enums\TicketCategory;
use App\Helpdesk\Enums\TicketStatus;
use App\Helpdesk\Models\SupportTicket;
use App\Helpdesk\Services\ComplaintTimelinesReport;
use App\Helpdesk\Services\HelpdeskService;
use App\Models\Admin;
use App\Models\User;
use App\Support\AdminPermissions;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

function timelineTicket(string $subject): SupportTicket
{
    return app(HelpdeskService::class)->openCustomerTicket(
        user: User::factory()->create(),
        category: TicketCategory::Other,
        subject: $subject,
        body: 'Something is wrong with my account.',
    );
}

function timelineViewer(): Admin
{
    $admin = Admin::factory()->create(['is_active' => true]);
    $role = Role::findOrCreate('Timelines '.uniqid(), 'admin');
    $role->givePermissionTo([AdminPermissions::SUPPORT_VIEW, AdminPermissions::SUPPORT_REPLY]);
    $admin->assignRole($role);

    return $admin->fresh();
}

beforeEach(function () {
    Artisan::call('permissions:sync');
    Mail::fake();
});

test('the inbox shows age, warns from day 25 and flags unresolved tickets over 30 days', function () {
    $admin = timelineViewer();

    $this->travelTo(now()->subDays(31), fn () => timelineTicket('Very old complaint'));
    $this->travelTo(now()->subDays(26), fn () => timelineTicket('Ageing complaint'));
    $fresh = timelineTicket('New complaint');

    $old = SupportTicket::query()->where('subject', 'Very old complaint')->sole();
    $ageing = SupportTicket::query()->where('subject', 'Ageing complaint')->sole();
    expect($old->resolutionDeadlineState())->toBe('overdue')
        ->and($ageing->resolutionDeadlineState())->toBe('warning')
        ->and($fresh->resolutionDeadlineState())->toBeNull();

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.support-index')
        ->assertSee('Overdue')
        ->set('status', 'overdue')
        ->assertSee('Very old complaint')
        ->assertDontSee('Ageing complaint')
        ->assertDontSee('New complaint');

    // Resolved tickets stop counting
    app(HelpdeskService::class)->setStatus($old, $admin, TicketStatus::Resolved);
    expect($old->fresh()->resolutionDeadlineState())->toBeNull();
});

test('the timelines report counts acknowledgement, resolution and first replies for the month', function () {
    $admin = timelineViewer();
    $month = CarbonImmutable::parse('2026-09-01');

    // Resolved in 2 days, first reply after 3 hours
    $this->travelTo($month->addDays(1), fn () => timelineTicket('Quick one'));
    $quick = SupportTicket::query()->where('subject', 'Quick one')->sole();
    $this->travelTo($month->addDays(1)->addHours(3), fn () => app(HelpdeskService::class)->replyAsStaff($quick, $admin, 'Looking into it.'));
    $this->travelTo($month->addDays(3), fn () => app(HelpdeskService::class)->setStatus($quick->fresh(), $admin, TicketStatus::Resolved));

    // Resolved after 40 days
    $this->travelTo($month->addDays(2), fn () => timelineTicket('Slow one'));
    $slow = SupportTicket::query()->where('subject', 'Slow one')->sole();
    $this->travelTo($month->addDays(42), fn () => app(HelpdeskService::class)->setStatus($slow, $admin, TicketStatus::Closed));

    // Still open past 30 days
    $this->travelTo($month->addDays(5), fn () => timelineTicket('Forgotten one'));

    // Another month: not counted
    $this->travelTo($month->subDays(3), fn () => timelineTicket('August one'));

    $this->travelTo($month->addDays(60));
    $report = app(ComplaintTimelinesReport::class)->forMonth($month);

    expect($report['received'])->toBe(3)
        ->and($report['acknowledged'])->toBe(3)
        ->and($report['staff_replied_within_ack'])->toBe(1)
        ->and($report['resolved_within_deadline'])->toBe(1)
        ->and($report['resolved_late'])->toBe(1)
        ->and($report['open_past_deadline'])->toBe(1)
        ->and($report['median_first_response_hours'])->toBe(3.0);

    Livewire::actingAs($admin, 'admin')
        ->withQueryParams(['month' => '2026-09'])
        ->test('pages::admin.support-report')
        ->assertSee('Complaint Timelines')
        ->assertSeeHtml('data-test="report-received">3<')
        ->assertSee('33%');
});

test('seller tickets are not counted as consumer complaints', function () {
    $admin = timelineViewer();
    timelineTicket('Customer complaint');
    SupportTicket::query()->first()->replicate(['ticket_number'])->fill([
        'ticket_number' => 'SUP-TEST-SELL',
        'requester_type' => 'seller',
    ])->save();

    expect(app(ComplaintTimelinesReport::class)->forMonth(now())['received'])->toBe(1);
});

test('report needs support.view', function () {
    $this->actingAs(Admin::factory()->create(['is_active' => true]), 'admin')
        ->get(route('admin.support.report'))
        ->assertForbidden();
});
