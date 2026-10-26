<?php

namespace App\Shipping;

use App\Shipping\Commands\PollShipmentTrackingCommand;
use App\Shipping\Commands\RegisterSellerWarehousesCommand;
use App\Shipping\Contracts\CarrierManagerInterface;
use App\Shipping\Contracts\DeliveryServiceabilityLookupInterface;
use App\Shipping\Contracts\ShipmentLookupInterface;
use App\Shipping\Contracts\ShipmentServiceInterface;
use App\Shipping\Services\CarrierManager;
use App\Shipping\Services\DeliveryServiceabilityLookupService;
use App\Shipping\Services\ShipmentLookupService;
use App\Shipping\Services\ShipmentService;
use Illuminate\Support\ServiceProvider;

class ShippingServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ShipmentServiceInterface::class => ShipmentService::class,
        DeliveryServiceabilityLookupInterface::class => DeliveryServiceabilityLookupService::class,
        ShipmentLookupInterface::class => ShipmentLookupService::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(
            config_path('shipping.php'), 'shipping'
        );

        $this->app->singleton(CarrierManagerInterface::class, CarrierManager::class);
    }

    public function boot(): void
    {
        $this->loadRoutesFrom(__DIR__.'/routes/shipping.php');

        $this->commands([
            PollShipmentTrackingCommand::class,
            RegisterSellerWarehousesCommand::class,
        ]);
    }
}
