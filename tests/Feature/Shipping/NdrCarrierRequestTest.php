<?php

use App\Shipping\Drivers\EkartCarrierDriver;
use App\Shipping\Drivers\ShiprocketCarrierDriver;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    // 28 Oct 2026, 23:30 IST: late evening, so "tomorrow" must be computed in IST, not UTC
    $this->travelTo(CarbonImmutable::parse('2026-10-28 23:30:00', 'Asia/Kolkata'));
    config(['app.display_timezone' => 'Asia/Kolkata']);
});

function ekartNdrDriver(): EkartCarrierDriver
{
    Http::fake([
        'https://app.elite.ekartlogistics.in/integrations/v2/auth/token/*' => Http::response(['access_token' => 'ek-token', 'expires_in' => 86400]),
        'https://app.elite.ekartlogistics.in/api/v2/package/ndr' => Http::response(['status' => true, 'remark' => 'ok']),
    ]);

    return new EkartCarrierDriver([
        'base_url' => 'https://app.elite.ekartlogistics.in',
        'client_id' => 'test-client-123',
        'username' => 'test-user',
        'password' => 'secret-pass',
        'safe_mode' => false,
    ]);
}

function shiprocketNdrDriver(): ShiprocketCarrierDriver
{
    Http::fake([
        'https://apiv2.shiprocket.in/v1/external/auth/login' => Http::response(['token' => 'sr-token']),
        'https://apiv2.shiprocket.in/v1/external/ndr/*' => Http::response(['status' => true]),
    ]);

    return new ShiprocketCarrierDriver([
        'base_url' => 'https://apiv2.shiprocket.in/v1/external',
        'email' => 'api@khilauna.com',
        'password' => 'secret-password',
        'safe_mode' => false,
    ]);
}

function sentNdrRequests(string $urlFragment): array
{
    return Http::recorded(fn (Request $request) => str_contains($request->url(), $urlFragment))
        ->map(fn (array $pair) => $pair[0])
        ->values()
        ->all();
}

test('ekart re-attempt posts ndr_data_v2 to /api/v2/package/ndr with date in ms', function () {
    $result = ekartNdrDriver()->requestNdrAction('EK123', 'reattempt', [
        'reattempt_date' => '2026-10-30',
        'phone' => '9876543210',
        'address' => 'Flat 4, MG Road, Bengaluru',
        'instructions' => 'Call before delivery',
    ]);

    expect($result['success'])->toBeTrue();

    $requests = sentNdrRequests('/api/v2/package/ndr');
    expect($requests)->toHaveCount(1);

    /** @var Request $request */
    $request = $requests[0];
    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://app.elite.ekartlogistics.in/api/v2/package/ndr')
        ->and($request->data())->toBe([
            'action' => 'Re-Attempt',
            'wbn' => 'EK123',
            'date' => CarbonImmutable::parse('2026-10-30 00:00:00', 'Asia/Kolkata')->getTimestamp() * 1000,
            'phone' => '9876543210',
            'address' => 'Flat 4, MG Road, Bengaluru',
            'instructions' => 'Call before delivery',
        ]);
});

test('ekart rto sends only action, wbn and instructions', function () {
    $result = ekartNdrDriver()->requestNdrAction('EK123', 'rto', [
        'instructions' => 'Customer refused',
    ]);

    expect($result['success'])->toBeTrue()
        ->and(sentNdrRequests('/api/v2/package/ndr')[0]->data())->toBe([
            'action' => 'RTO',
            'wbn' => 'EK123',
            'instructions' => 'Customer refused',
        ]);
});

test('ekart re-attempt date must be within seven days excluding today', function (?string $date, bool $accepted) {
    $payload = $date === null ? [] : ['reattempt_date' => $date];

    $result = ekartNdrDriver()->requestNdrAction('EK123', 'reattempt', $payload);

    expect($result['success'])->toBe($accepted)
        ->and(sentNdrRequests('/api/v2/package/ndr'))->toHaveCount($accepted ? 1 : 0);

    if (! $accepted) {
        expect($result['message'])->toContain('Ekart re-attempt');
    }
})->with([
    'tomorrow (IST)' => ['2026-10-29', true],
    'seventh day' => ['2026-11-04', true],
    'today' => ['2026-10-28', false],
    'yesterday' => ['2026-10-27', false],
    'eighth day' => ['2026-11-05', false],
    'impossible date' => ['2026-02-30', false],
    'wrong format' => ['29/10/2026', false],
    'missing' => [null, false],
]);

test('ekart rejects a phone that is not exactly 10 digits', function () {
    $result = ekartNdrDriver()->requestNdrAction('EK123', 'reattempt', [
        'reattempt_date' => '2026-10-29',
        'phone' => '98765',
    ]);

    expect($result['success'])->toBeFalse()
        ->and(sentNdrRequests('/api/v2/package/ndr'))->toHaveCount(0);
});

test('shiprocket re-attempt posts documented Action NDR fields to /v1/external/ndr/{awb}/action', function () {
    $result = shiprocketNdrDriver()->requestNdrAction('SR999', 'reattempt', [
        'reattempt_date' => '2026-10-30',
        'phone' => '9876543210',
        'address' => 'Flat 4, MG Road, Bengaluru',
        'instructions' => 'Call before delivery',
    ]);

    expect($result['success'])->toBeTrue();

    $request = sentNdrRequests('/ndr/')[0];
    expect($request->method())->toBe('POST')
        ->and($request->url())->toBe('https://apiv2.shiprocket.in/v1/external/ndr/SR999/action')
        ->and($request->data())->toBe([
            'action' => 're-attempt',
            'comments' => 'Call before delivery',
            'phone' => '9876543210',
            'address1' => 'Flat 4, MG Road, Bengaluru',
            'deferred_date' => '2026-10-30',
        ]);
});

test('shiprocket return sends only action and comments', function () {
    $result = shiprocketNdrDriver()->requestNdrAction('SR999', 'rto', [
        'phone' => '9876543210',
        'address' => 'ignored for return',
        'reattempt_date' => '2026-10-30',
    ]);

    expect($result['success'])->toBeTrue()
        ->and(sentNdrRequests('/ndr/')[0]->data())->toBe([
            'action' => 'return',
            'comments' => 'RTO requested by admin',
        ]);
});

test('shiprocket rejects a deferred date that is not in the future', function () {
    $result = shiprocketNdrDriver()->requestNdrAction('SR999', 'reattempt', [
        'reattempt_date' => '2026-10-28',
    ]);

    expect($result['success'])->toBeFalse()
        ->and(sentNdrRequests('/ndr/'))->toHaveCount(0);
});
