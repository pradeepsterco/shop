<?php

use App\Branding\BrandingServiceProvider;
use App\Catalog\CatalogServiceProvider;
use App\Content\ContentServiceProvider;
use App\Coupons\CouponsServiceProvider;
use App\Fees\FeesServiceProvider;
use App\Helpdesk\HelpdeskServiceProvider;
use App\Orders\OrdersServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Returns\ReturnsServiceProvider;
use App\Reviews\ReviewsServiceProvider;
use App\Search\SearchServiceProvider;
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
    ReturnsServiceProvider::class,
    BrandingServiceProvider::class,
    ContentServiceProvider::class,
    CouponsServiceProvider::class,
    ReviewsServiceProvider::class,
    SearchServiceProvider::class,
    HelpdeskServiceProvider::class,
    ServiceProvider::class,
];
