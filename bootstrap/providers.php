<?php

use App\Catalog\CatalogServiceProvider;
use App\Fees\FeesServiceProvider;
use App\Orders\OrdersServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Sellers\SellersServiceProvider;
use App\Settlements\SettlementsServiceProvider;
use App\Shipping\ShippingServiceProvider;
use Onecentlin\Adminer\ServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    CatalogServiceProvider::class,
    OrdersServiceProvider::class,
    FeesServiceProvider::class,
    SellersServiceProvider::class,
    SettlementsServiceProvider::class,
    ShippingServiceProvider::class,
    ServiceProvider::class,
];
