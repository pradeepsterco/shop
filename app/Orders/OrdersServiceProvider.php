<?php

namespace App\Orders;

use App\Orders\Contracts\OrderItemsFeeLookupInterface;
use App\Orders\Contracts\SellerOrderFulfillmentInterface;
use App\Orders\Contracts\SellerOrderShippingDetailsLookupInterface;
use App\Orders\Contracts\SellerOrderStatusLookupInterface;
use App\Orders\Services\OrderItemsFeeLookupService;
use App\Orders\Services\SellerOrderFulfillmentService;
use App\Orders\Services\SellerOrderShippingDetailsLookupService;
use App\Orders\Services\SellerOrderStatusLookupService;
use Illuminate\Support\ServiceProvider;

class OrdersServiceProvider extends ServiceProvider
{
    /**
     * All of the container bindings that should be registered.
     *
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        OrderItemsFeeLookupInterface::class => OrderItemsFeeLookupService::class,
        SellerOrderStatusLookupInterface::class => SellerOrderStatusLookupService::class,
        SellerOrderFulfillmentInterface::class => SellerOrderFulfillmentService::class,
        SellerOrderShippingDetailsLookupInterface::class => SellerOrderShippingDetailsLookupService::class,
    ];

    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
