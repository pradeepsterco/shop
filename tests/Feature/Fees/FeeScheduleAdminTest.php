<?php

use App\Fees\Contracts\FeeScheduleServiceInterface;
use App\Fees\Models\FeeClosingSlab;
use App\Fees\Models\FeeCommissionSlab;
use App\Fees\Models\FeeSchedule;
use App\Fees\Models\FeeShippingSlab;
use App\Models\Admin;
use App\Models\AdminActivityLog;
use App\Shared\Enums\FeeScheduleStatus;
use App\Shared\Enums\ShippingZone;
use App\Support\AdminPermissions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

uses(RefreshDatabase::class);

beforeEach(function () {
    app()[PermissionRegistrar::class]->forgetCachedPermissions();
    Permission::findOrCreate(AdminPermissions::FEES_VIEW, 'admin');
    Permission::findOrCreate(AdminPermissions::FEES_MANAGE, 'admin');
});

test('admin without fees.view permission cannot access fee pages', function () {
    $admin = Admin::factory()->create(['is_active' => true]);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.fees.index'))
        ->assertForbidden();
});

test('admin with fees.view can view fee schedules index and details', function () {
    $admin = Admin::factory()->create(['is_active' => true]);
    $admin->givePermissionTo(AdminPermissions::FEES_VIEW);

    $schedule = FeeSchedule::create([
        'name' => 'View Test Schedule',
        'version' => 1,
        'status' => FeeScheduleStatus::Active,
        'effective_from' => now()->subDay(),
        'gst_rate' => 18.00,
        'tcs_rate' => 0.50,
        'tds_standard_rate' => 0.10,
        'tds_no_pan_rate' => 5.00,
        'default_weight_grams' => 500,
    ]);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.fees.index'))
        ->assertOk()
        ->assertSee('View Test Schedule');

    $this->actingAs($admin, 'admin')
        ->get(route('admin.fees.show', $schedule))
        ->assertOk()
        ->assertSee('View Test Schedule');
});

test('admin without fees.manage cannot edit or create fee schedules', function () {
    $admin = Admin::factory()->create(['is_active' => true]);
    $admin->givePermissionTo(AdminPermissions::FEES_VIEW); // has view, but lacks manage

    $schedule = FeeSchedule::create([
        'name' => 'Draft Schedule',
        'version' => 1,
        'status' => FeeScheduleStatus::Draft,
        'gst_rate' => 18.00,
        'tcs_rate' => 0.50,
        'tds_standard_rate' => 0.10,
        'tds_no_pan_rate' => 5.00,
        'default_weight_grams' => 500,
    ]);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.fees.create'))
        ->assertForbidden();

    $this->actingAs($admin, 'admin')
        ->get(route('admin.fees.edit', $schedule))
        ->assertForbidden();
});

test('admin with fees.manage can access create page and save a draft schedule', function () {
    $admin = Admin::factory()->create(['is_active' => true]);
    $admin->givePermissionTo(AdminPermissions::FEES_MANAGE);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.fees.create'))
        ->assertOk()
        ->assertSee('Create Draft Fee Schedule');

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.fees-edit')
        ->set('name', 'Custom Festive Schedule')
        ->set('gst_rate', '18.00')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    expect(FeeSchedule::where('name', 'Custom Festive Schedule')->exists())->toBeTrue();
});

test('admin with fees.manage can edit an existing draft schedule', function () {
    $admin = Admin::factory()->create(['is_active' => true]);
    $admin->givePermissionTo(AdminPermissions::FEES_MANAGE);

    $draft = FeeSchedule::create([
        'name' => 'Initial Draft',
        'version' => 1,
        'status' => FeeScheduleStatus::Draft,
        'gst_rate' => 18.00,
        'tcs_rate' => 0.50,
        'tds_standard_rate' => 0.10,
        'tds_no_pan_rate' => 5.00,
        'default_weight_grams' => 500,
    ]);
    FeeClosingSlab::create(['fee_schedule_id' => $draft->id, 'min_price' => 0, 'max_price' => null, 'fee_amount' => 2000]);
    FeeShippingSlab::create(['fee_schedule_id' => $draft->id, 'zone' => ShippingZone::Local, 'min_weight_grams' => 0, 'max_weight_grams' => null, 'fee_amount' => 3000, 'is_default' => true]);
    FeeShippingSlab::create(['fee_schedule_id' => $draft->id, 'zone' => ShippingZone::Regional, 'min_weight_grams' => 0, 'max_weight_grams' => null, 'fee_amount' => 4000, 'is_default' => true]);
    FeeShippingSlab::create(['fee_schedule_id' => $draft->id, 'zone' => ShippingZone::National, 'min_weight_grams' => 0, 'max_weight_grams' => null, 'fee_amount' => 5000, 'is_default' => true]);

    $this->actingAs($admin, 'admin')
        ->get(route('admin.fees.edit', $draft))
        ->assertOk()
        ->assertSee('Edit Draft Fee Schedule');

    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.fees-edit', ['feeSchedule' => $draft->id])
        ->set('name', 'Renamed Draft Schedule')
        ->call('save')
        ->assertHasNoErrors();

    expect($draft->fresh()->name)->toBe('Renamed Draft Schedule');
});

test('slabs continuity validation detects gaps and overlaps', function () {
    /** @var FeeScheduleServiceInterface $service */
    $service = app(FeeScheduleServiceInterface::class);

    // 1. Continuous slabs: [0, 500] and [501, 1000] and [1001, null] -> VALID
    $validSlabs = [
        ['min' => 0, 'max' => 500],
        ['min' => 501, 'max' => 1000],
        ['min' => 1001, 'max' => null],
    ];
    expect($service->validateSlabsContinuity($validSlabs))->toBeEmpty();

    // 2. Overlapping slabs: [0, 500] and [450, 1000] -> OVERLAP ERROR
    $overlappingSlabs = [
        ['min' => 0, 'max' => 500],
        ['min' => 450, 'max' => 1000],
    ];
    $overlapErrors = $service->validateSlabsContinuity($overlappingSlabs);
    expect($overlapErrors)->toHaveCount(1)
        ->and($overlapErrors[0])->toContain('Overlap detected');

    // 3. Gap in slabs: [0, 500] and [600, 1000] -> GAP ERROR
    $gappedSlabs = [
        ['min' => 0, 'max' => 500],
        ['min' => 600, 'max' => 1000],
    ];
    $gapErrors = $service->validateSlabsContinuity($gappedSlabs);
    expect($gapErrors)->toHaveCount(1)
        ->and($gapErrors[0])->toContain('Gap detected between 501 and 599');
});

test('activating a schedule archives the previous active schedule at the exact same instant', function () {
    /** @var FeeScheduleServiceInterface $service */
    $service = app(FeeScheduleServiceInterface::class);

    $oldActive = FeeSchedule::create([
        'name' => 'Schedule V1',
        'version' => 1,
        'status' => FeeScheduleStatus::Active,
        'effective_from' => now()->subMonths(3),
        'effective_to' => null,
        'gst_rate' => 18.00,
        'tcs_rate' => 0.50,
        'tds_standard_rate' => 0.10,
        'tds_no_pan_rate' => 5.00,
        'default_weight_grams' => 500,
    ]);

    $draftV2 = FeeSchedule::create([
        'name' => 'Schedule V2',
        'version' => 2,
        'status' => FeeScheduleStatus::Draft,
        'effective_from' => null,
        'effective_to' => null,
        'gst_rate' => 18.00,
        'tcs_rate' => 0.50,
        'tds_standard_rate' => 0.10,
        'tds_no_pan_rate' => 5.00,
        'default_weight_grams' => 500,
    ]);

    // Activate V2
    $activatedV2 = $service->activateSchedule($draftV2);
    $oldActive->refresh();

    // Exactly one active schedule remaining
    expect(FeeSchedule::where('status', FeeScheduleStatus::Active)->count())->toBe(1)
        ->and($activatedV2->status)->toBe(FeeScheduleStatus::Active)
        ->and($activatedV2->effective_from)->not->toBeNull()
        ->and($activatedV2->effective_to)->toBeNull()
        ->and($oldActive->status)->toBe(FeeScheduleStatus::Archived)
        ->and($oldActive->effective_to->toDateTimeString())->toBe($activatedV2->effective_from->toDateTimeString());
});

test('effective dating applies historical schedule to past orders', function () {
    /** @var FeeScheduleServiceInterface $service */
    $service = app(FeeScheduleServiceInterface::class);

    // Schedule 1: Jan 1 to Mar 31 (Commission 10%)
    $sched1 = FeeSchedule::create([
        'name' => 'Q1 Schedule',
        'version' => 1,
        'status' => FeeScheduleStatus::Archived,
        'effective_from' => now()->parse('2026-01-01 00:00:00'),
        'effective_to' => now()->parse('2026-03-31 23:59:59'),
        'gst_rate' => 18.00,
        'tcs_rate' => 0.50,
        'tds_standard_rate' => 0.10,
        'tds_no_pan_rate' => 5.00,
        'default_weight_grams' => 500,
    ]);
    FeeCommissionSlab::create([
        'fee_schedule_id' => $sched1->id,
        'min_price' => 0,
        'max_price' => null,
        'rate_bps' => 1000, // 10%
    ]);

    // Schedule 2: Apr 1 onward (Commission 15%)
    $sched2 = FeeSchedule::create([
        'name' => 'Q2 Schedule',
        'version' => 2,
        'status' => FeeScheduleStatus::Active,
        'effective_from' => now()->parse('2026-04-01 00:00:00'),
        'effective_to' => null,
        'gst_rate' => 18.00,
        'tcs_rate' => 0.50,
        'tds_standard_rate' => 0.10,
        'tds_no_pan_rate' => 5.00,
        'default_weight_grams' => 500,
    ]);
    FeeCommissionSlab::create([
        'fee_schedule_id' => $sched2->id,
        'min_price' => 0,
        'max_price' => null,
        'rate_bps' => 1500, // 15%
    ]);

    // Order placed in February 2026 gets Schedule 1
    $febOrderDate = now()->parse('2026-02-15 12:00:00');
    $resolvedFeb = $service->getScheduleForDate($febOrderDate);
    expect($resolvedFeb->id)->toBe($sched1->id);

    // Order placed in May 2026 gets Schedule 2
    $mayOrderDate = now()->parse('2026-05-10 12:00:00');
    $resolvedMay = $service->getScheduleForDate($mayOrderDate);
    expect($resolvedMay->id)->toBe($sched2->id);
});

test('fee schedule create, edit, activate, and clone are written to the admin activity log', function () {
    $admin = Admin::factory()->create(['is_active' => true]);
    $admin->givePermissionTo(AdminPermissions::FEES_MANAGE);
    $admin->givePermissionTo(AdminPermissions::FEES_VIEW);

    // 1. Create Draft
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.fees-edit')
        ->set('name', 'Logged Schedule V1')
        ->call('save')
        ->assertHasNoErrors();

    $created = FeeSchedule::where('name', 'Logged Schedule V1')->firstOrFail();
    expect(AdminActivityLog::where('action', 'fee_schedule.created')->where('subject_id', $created->id)->exists())->toBeTrue();

    // 2. Edit Draft
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.fees-edit', ['feeSchedule' => $created->id])
        ->set('name', 'Logged Schedule V1 Updated')
        ->call('save')
        ->assertHasNoErrors();

    expect(AdminActivityLog::where('action', 'fee_schedule.updated')->where('subject_id', $created->id)->exists())->toBeTrue();

    // 3. Clone Schedule
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.fees-index')
        ->call('cloneSchedule', $created->id);

    $cloned = FeeSchedule::where('name', 'Logged Schedule V1 Updated')->where('version', 2)->firstOrFail();
    expect(AdminActivityLog::where('action', 'fee_schedule.cloned')->where('subject_id', $cloned->id)->exists())->toBeTrue();

    // 4. Activate Schedule
    Livewire::actingAs($admin, 'admin')
        ->test('pages::admin.fees-index')
        ->call('confirmActivate', $cloned->id)
        ->call('activate');

    expect(AdminActivityLog::where('action', 'fee_schedule.activated')->where('subject_id', $cloned->id)->exists())->toBeTrue();
});
