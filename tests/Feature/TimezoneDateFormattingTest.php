<?php

use App\Models\Admin;
use App\Models\SellerProfile;
use App\Models\User;
use App\Orders\Enums\OrderStatus;
use App\Orders\Enums\PaymentMethod;
use App\Orders\Enums\PaymentStatus;
use App\Orders\Models\Order;
use App\Settlements\Contracts\PayoutServiceInterface;
use App\Settlements\DTOs\PayoutBatchCreateInput;
use App\Settlements\Enums\SettlementStatus;
use App\Settlements\Models\SellerOrderSettlement;
use App\Support\AdminPermissions;
use App\Support\DateFormatter;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Artisan::call('permissions:sync');
});

function createTestOrderWithPlacedAt(CarbonImmutable $placedAt, ?User $user = null): Order
{
    $user ??= User::factory()->create();

    return Order::create([
        'order_number' => 'ORD-'.Str::upper(Str::random(8)),
        'user_id' => $user->id,
        'checkout_intent_id' => (string) Str::ulid(),
        'status' => OrderStatus::Placed,
        'delivery_address' => [
            'name' => 'Test User',
            'phone' => '9876543210',
            'address_line_1' => 'MG Road',
            'city' => 'Bengaluru',
            'state' => 'Karnataka',
            'pincode' => '560001',
        ],
        'delivery_state' => 'Karnataka',
        'payment_method' => PaymentMethod::CashOnDelivery,
        'payment_status' => PaymentStatus::Pending,
        'items_mrp_total' => 100000,
        'discount_total' => 0,
        'delivery_fee' => 0,
        'grand_total' => 100000,
        'placed_at' => $placedAt,
    ]);
}

test('UTC 12:00 timestamp renders as 5:30 PM IST via formatter and helper', function () {
    $utcTimestamp = CarbonImmutable::parse('2026-10-25 12:00:00', 'UTC');

    // 1. App\Support\DateFormatter
    $formatted = DateFormatter::format($utcTimestamp);
    expect($formatted)->toBe('25 Oct 2026, 5:30 PM IST');

    // 2. Global helper format_datetime
    expect(format_datetime($utcTimestamp))->toBe('25 Oct 2026, 5:30 PM IST')
        ->and(format_datetime('2026-10-25 12:00:00'))->toBe('25 Oct 2026, 5:30 PM IST');

    // 3. Date only helper format_date
    expect(format_date($utcTimestamp))->toBe('25 Oct 2026');
});

test('Blade directive @datetime and @date render in India display timezone', function () {
    $utcTimestamp = CarbonImmutable::parse('2026-10-25 12:00:00', 'UTC');

    $datetimeHtml = Blade::render('@datetime($date)', ['date' => $utcTimestamp]);
    expect(trim($datetimeHtml))->toBe('25 Oct 2026, 5:30 PM IST');

    $dateHtml = Blade::render('@date($date)', ['date' => $utcTimestamp]);
    expect(trim($dateHtml))->toBe('25 Oct 2026');
});

test('date filters (today) use India day boundaries', function () {
    // Current time: 25 Oct 2026, 06:00:00 UTC = 11:30 AM IST (Sunday in India)
    Carbon::setTestNow('2026-10-25 06:00:00');

    try {
        $user = User::factory()->create();

        // Order 1: placed on 24 Oct 2026 19:30:00 UTC = 25 Oct 2026 01:00:00 IST (Today in India!)
        $todayOrder = createTestOrderWithPlacedAt(CarbonImmutable::parse('2026-10-24 19:30:00', 'UTC'), $user);

        // Order 2: placed on 24 Oct 2026 18:00:00 UTC = 24 Oct 2026 23:30:00 IST (Yesterday in India!)
        $yesterdayOrder = createTestOrderWithPlacedAt(CarbonImmutable::parse('2026-10-24 18:00:00', 'UTC'), $user);

        // Order 3: placed on 25 Oct 2026 19:00:00 UTC = 26 Oct 2026 00:30:00 IST (Tomorrow in India!)
        $tomorrowOrder = createTestOrderWithPlacedAt(CarbonImmutable::parse('2026-10-25 19:00:00', 'UTC'), $user);

        // Query using DateFormatter::todayRange()
        [$startUtc, $endUtc] = DateFormatter::todayRange();
        $results = Order::whereBetween('placed_at', [$startUtc, $endUtc])->pluck('id')->all();

        expect($results)->toContain($todayOrder->id)
            ->and($results)->not->toContain($yesterdayOrder->id)
            ->and($results)->not->toContain($tomorrowOrder->id);

        // Livewire Admin Orders component filter by today
        $admin = Admin::factory()->create();
        $admin->givePermissionTo(AdminPermissions::ORDERS_VIEW);

        $component = Livewire::actingAs($admin, 'admin')
            ->test('pages::admin.orders-index')
            ->set('datePreset', 'today');

        $component->assertSee($todayOrder->order_number)
            ->assertDontSee($yesterdayOrder->order_number)
            ->assertDontSee($tomorrowOrder->order_number);
    } finally {
        Carbon::setTestNow();
    }
});

test('date filters (last 7 days) use India day boundaries', function () {
    // Current time: 25 Oct 2026, 06:00:00 UTC = 11:30 AM IST
    Carbon::setTestNow('2026-10-25 06:00:00');

    try {
        $user = User::factory()->create();

        // Order inside 7 days: 20 Oct 2026 (5 days ago in India)
        $insideOrder = createTestOrderWithPlacedAt(CarbonImmutable::parse('2026-10-20 12:00:00', 'UTC'), $user);

        // Order outside 7 days: 17 Oct 2026 18:00:00 UTC = 17 Oct 2026 23:30:00 IST (8 days ago in India)
        $outsideOrder = createTestOrderWithPlacedAt(CarbonImmutable::parse('2026-10-17 18:00:00', 'UTC'), $user);

        [$startUtc, $endUtc] = DateFormatter::lastDaysRange(7);
        $results = Order::whereBetween('placed_at', [$startUtc, $endUtc])->pluck('id')->all();

        expect($results)->toContain($insideOrder->id)
            ->and($results)->not->toContain($outsideOrder->id);
    } finally {
        Carbon::setTestNow();
    }
});

test('scheduled payout batch command respects Asia/Kolkata payout day', function () {
    // 25 Oct 2026 is a Sunday in Asia/Kolkata
    Carbon::setTestNow('2026-10-25 06:00:00');

    try {
        // On Sunday, command should skip
        $this->artisan('settlements:payout-batch')
            ->expectsOutputToContain('is not the configured payout day (wednesday) in Asia/Kolkata. Skipping.')
            ->assertSuccessful();

        // Travel to Wednesday: 28 Oct 2026 06:00:00 IST = 28 Oct 2026 00:30:00 UTC
        Carbon::setTestNow('2026-10-28 00:30:00');

        $this->artisan('settlements:payout-batch')
            ->expectsOutputToContain('Creating weekly payout batch for 2026-10-28 (Asia/Kolkata)...')
            ->assertSuccessful();
    } finally {
        Carbon::setTestNow();
    }
});

test('CSV export displays payout created timestamp in Asia/Kolkata time', function () {
    $user = User::factory()->create();
    $profile = SellerProfile::factory()->create([
        'user_id' => $user->id,
        'status' => 'approved',
        'store_name' => 'CSV Time Store',
        'bank_account_holder' => 'Account Holder',
        'bank_account_number' => '987654321012',
        'ifsc' => 'HDFC0001234',
        'reviewed_at' => now()->subDays(10),
        'bank_updated_at' => now()->subDays(15),
    ]);

    $payoutService = app(PayoutServiceInterface::class);

    SellerOrderSettlement::create([
        'seller_order_id' => 'SO-CSV-TIME',
        'order_id' => 'ORD-CSV',
        'seller_profile_id' => $profile->id,
        'gross_amount' => 200000,
        'total_deductions' => 30000,
        'net_payout' => 170000,
        'claimable_credits' => 3000,
        'status' => SettlementStatus::Eligible,
        'return_window_days' => 7,
    ]);

    // Freeze time at 12:00:00 UTC = 5:30:00 PM IST
    Carbon::setTestNow('2026-10-25 12:00:00');

    try {
        $input = new PayoutBatchCreateInput(scheduledPayoutDate: CarbonImmutable::now());
        $result = $payoutService->createWeeklyPayoutBatch($input);
        $batch = $result->batch;
        expect($batch)->not->toBeNull();

        $csv = $payoutService->generateBankCsv($batch->id);

        // The CSV should contain the date rendered in India time (5:30 PM IST)
        expect($csv)->toContain('25 Oct 2026, 5:30 PM IST');
    } finally {
        Carbon::setTestNow();
    }
});
