<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Default Shipping Carrier Driver
    |--------------------------------------------------------------------------
    |
    | Supported: 'ekart', 'shiprocket', 'manual', 'fake'
    |
    */

    'default' => env('SHIPPING_CARRIER_DEFAULT', 'ekart'),

    /*
    |--------------------------------------------------------------------------
    | Safe Mode
    |--------------------------------------------------------------------------
    |
    | When enabled, real API calls that create packages/shipments on Ekart's
    | production endpoint are blocked to prevent accidental real orders.
    | Must be explicitly set to false to allow live API shipment creation.
    |
    */

    'safe_mode' => (bool) env('SHIPPING_SAFE_MODE', true),

    /*
    |--------------------------------------------------------------------------
    | Serviceability Cache TTL
    |--------------------------------------------------------------------------
    |
    | Duration (in hours) to cache pincode serviceability results.
    |
    */

    'serviceability_cache_ttl_hours' => 4,

    /*
    |--------------------------------------------------------------------------
    | Hard Limits
    |--------------------------------------------------------------------------
    |
    | Maximum allowed Cash on Delivery (COD) amount in INR per package.
    | Ekart hard limit: ₹49,999.
    |
    */

    'cod_max_limit_inr' => 49999,

    /*
    |--------------------------------------------------------------------------
    | SSL Verification & CA Certificate Bundle
    |--------------------------------------------------------------------------
    |
    | Used for cURL/OpenSSL peer certificate verification on Windows or custom setups.
    | Set SHIPPING_SSL_VERIFY=false in .env to disable SSL verification in local development.
    |
    */

    'ssl_verify' => env('SHIPPING_SSL_VERIFY', true),
    'ca_bundle_path' => env('SHIPPING_CA_BUNDLE_PATH', null),

    /*
    |--------------------------------------------------------------------------
    | Volumetric Divisor
    |--------------------------------------------------------------------------
    |
    | Standard divisor for volumetric weight: (L * W * H in cm) / 5000 = kg.
    |
    */

    'volumetric_divisor' => 5000,

    /*
    |--------------------------------------------------------------------------
    | Ekart Carrier Settings
    |--------------------------------------------------------------------------
    |
    | Credentials and configuration for Ekart Logistics API.
    |
    */

    'ekart' => [
        'base_url' => env('EKART_BASE_URL', 'https://app.elite.ekartlogistics.in'),
        'client_id' => env('EKART_CLIENT_ID', ''),
        'username' => env('EKART_USERNAME', ''),
        'password' => env('EKART_PASSWORD', ''),
        'service' => env('EKART_SERVICE', 'SURFACE'), // SURFACE or EXPRESS
        'webhook_secret' => env('EKART_WEBHOOK_SECRET', ''),
        'webhook_header' => env('EKART_WEBHOOK_HEADER', null),
        'webhook_algorithm' => env('EKART_WEBHOOK_ALGO', null),
        'timeout_seconds' => (int) env('EKART_TIMEOUT_SECONDS', 15),
        'retry_attempts' => (int) env('EKART_RETRY_ATTEMPTS', 3),
    ],

    /*
    |--------------------------------------------------------------------------
    | Shiprocket Carrier Settings
    |--------------------------------------------------------------------------
    |
    | Credentials, courier selection, and configuration for Shiprocket API.
    |
    */

    'shiprocket' => [
        'base_url' => env('SHIPROCKET_BASE_URL', 'https://apiv2.shiprocket.in/v1/external'),
        'email' => env('SHIPROCKET_EMAIL', ''),
        'password' => env('SHIPROCKET_PASSWORD', ''),
        'webhook_token' => env('SHIPROCKET_WEBHOOK_TOKEN', ''),
        'courier_selection_rule' => env('SHIPROCKET_COURIER_SELECTION', 'recommended'), // 'recommended', 'cheapest', 'fastest', 'specific'
        'specific_courier_id' => env('SHIPROCKET_SPECIFIC_COURIER_ID', null),
        'timeout_seconds' => (int) env('SHIPROCKET_TIMEOUT_SECONDS', 15),
        'retry_attempts' => (int) env('SHIPROCKET_RETRY_ATTEMPTS', 3),
    ],
];
