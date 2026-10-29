<?php

namespace App\Returns;

use App\Orders\Events\SellerOrderShipped;
use App\Returns\Contracts\CourierClaimServiceInterface;
use App\Returns\Contracts\ReturnRequestServiceInterface;
use App\Returns\Contracts\ReturnsLookupInterface;
use App\Returns\Contracts\RtoIntakeServiceInterface;
use App\Returns\Contracts\ShipmentExceptionServiceInterface;
use App\Returns\Contracts\UndeliveredItemServiceInterface;
use App\Returns\Listeners\HandleReshipOutcome;
use App\Returns\Listeners\HandleReturnPickupOutcome;
use App\Returns\Services\CourierClaimService;
use App\Returns\Services\ReturnRequestService;
use App\Returns\Services\ReturnsLookupService;
use App\Returns\Services\RtoIntakeService;
use App\Returns\Services\ShipmentExceptionService;
use App\Returns\Services\UndeliveredItemService;
use App\Shipping\Events\ShipmentReshipCancelled;
use App\Shipping\Events\ShipmentReshipDelivered;
use App\Shipping\Events\ShipmentReshipReturned;
use App\Shipping\Events\ShipmentReturnLost;
use App\Shipping\Events\ShipmentReturnPickupCancelled;
use App\Shipping\Events\ShipmentReturnReceived;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class ReturnsServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        ReturnsLookupInterface::class => ReturnsLookupService::class,
        RtoIntakeServiceInterface::class => RtoIntakeService::class,
        CourierClaimServiceInterface::class => CourierClaimService::class,
        ShipmentExceptionServiceInterface::class => ShipmentExceptionService::class,
        UndeliveredItemServiceInterface::class => UndeliveredItemService::class,
        ReturnRequestServiceInterface::class => ReturnRequestService::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/returns.php', 'returns');
    }

    public function boot(): void
    {
        Event::listen(ShipmentReshipDelivered::class, [HandleReshipOutcome::class, 'delivered']);
        Event::listen(ShipmentReshipReturned::class, [HandleReshipOutcome::class, 'returned']);
        Event::listen(ShipmentReshipCancelled::class, [HandleReshipOutcome::class, 'cancelled']);
        Event::listen(ShipmentReturnPickupCancelled::class, [HandleReturnPickupOutcome::class, 'cancelled']);
        Event::listen(ShipmentReturnReceived::class, [HandleReturnPickupOutcome::class, 'received']);
        Event::listen(ShipmentReturnLost::class, [HandleReturnPickupOutcome::class, 'lost']);
        Event::listen(SellerOrderShipped::class, [HandleReturnPickupOutcome::class, 'replacementShipped']);
    }
}
