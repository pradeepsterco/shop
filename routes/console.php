<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('cart:prune-guest-carts')->daily();
Schedule::command('checkout:prune-intents')->daily();

$displayTimezone = (string) config('app.display_timezone', 'Asia/Kolkata');

Schedule::command('settlements:release')
    ->dailyAt('00:05')
    ->timezone($displayTimezone);

Schedule::command('settlements:payout-batch')
    ->weeklyOn(3, '06:00') // Wednesday morning in local timezone
    ->timezone($displayTimezone);

Schedule::command('shipping:poll-tracking')
    ->everyFifteenMinutes();

Schedule::command('content:publish-scheduled')
    ->everyMinute();
