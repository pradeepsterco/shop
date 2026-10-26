<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Settlements Module Configuration
    |--------------------------------------------------------------------------
    */

    'timezone' => env('APP_DISPLAY_TIMEZONE', 'Asia/Kolkata'),

    'payout_day' => 'wednesday',

    'return_window_days' => 7,

    'minimum_payout_amount_paise' => 100000, // ₹1,000.00
];
