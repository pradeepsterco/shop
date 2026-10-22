<?php

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use Onecentlin\Adminer\ServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    ServiceProvider::class,
];
