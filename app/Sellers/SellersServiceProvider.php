<?php

namespace App\Sellers;

use App\Sellers\Contracts\SellerPayoutLookupInterface;
use App\Sellers\Contracts\SellerPickupAddressLookupInterface;
use App\Sellers\Services\SellerPayoutLookupService;
use App\Sellers\Services\SellerPickupAddressLookupService;
use Illuminate\Support\ServiceProvider;

class SellersServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        SellerPayoutLookupInterface::class => SellerPayoutLookupService::class,
        SellerPickupAddressLookupInterface::class => SellerPickupAddressLookupService::class,
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
