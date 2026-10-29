<?php

namespace App\Settlements;

use App\Fees\Events\FeesCalculated;
use App\Orders\Events\SellerOrderCancelled;
use App\Orders\Events\SellerOrderDelivered;
use App\Orders\Events\SellerOrderPartialDelivered;
use App\Orders\Events\SellerOrderReturnedToOrigin;
use App\Settlements\Commands\BackfillSettlementsCommand;
use App\Settlements\Commands\CreateWeeklyPayoutBatchCommand;
use App\Settlements\Commands\ReleaseEligibleSettlementsCommand;
use App\Settlements\Contracts\PayoutServiceInterface;
use App\Settlements\Contracts\SellerLedgerServiceInterface;
use App\Settlements\Contracts\SettlementServiceInterface;
use App\Settlements\Listeners\CreateSettlementOnFeesCalculated;
use App\Settlements\Listeners\HoldSettlementOnPartialDelivery;
use App\Settlements\Listeners\ProcessRtoSettlementOnOrderReturned;
use App\Settlements\Listeners\PutSettlementOnHoldOnDelivered;
use App\Settlements\Listeners\VoidSettlementOnOrderCancelled;
use App\Settlements\Services\PayoutService;
use App\Settlements\Services\SellerLedgerService;
use App\Settlements\Services\SettlementService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class SettlementsServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    public array $bindings = [
        SettlementServiceInterface::class => SettlementService::class,
        SellerLedgerServiceInterface::class => SellerLedgerService::class,
        PayoutServiceInterface::class => PayoutService::class,
    ];

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/settlements.php', 'settlements');
    }

    public function boot(): void
    {
        Event::listen(FeesCalculated::class, CreateSettlementOnFeesCalculated::class);
        Event::listen(SellerOrderDelivered::class, PutSettlementOnHoldOnDelivered::class);
        Event::listen(SellerOrderCancelled::class, VoidSettlementOnOrderCancelled::class);
        Event::listen(SellerOrderReturnedToOrigin::class, ProcessRtoSettlementOnOrderReturned::class);
        Event::listen(SellerOrderPartialDelivered::class, HoldSettlementOnPartialDelivery::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                ReleaseEligibleSettlementsCommand::class,
                BackfillSettlementsCommand::class,
                CreateWeeklyPayoutBatchCommand::class,
            ]);
        }
    }
}
