<?php

return [
    'enabled' => env('ADMINER_ENABLED', true),
    'autologin' => env('ADMINER_AUTO_LOGIN', false),
    'route_prefix' => env('ADMINER_ROUTE_PREFIX', 'adminer'),
    'middleware' => ['web'],
    'plugins' => [
        // 'Adminer\Plugins\AutoLogin',
    ],
];
