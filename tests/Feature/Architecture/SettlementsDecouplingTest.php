<?php

test('App\Settlements subsystem does not directly depend on Orders internal models or services', function () {
    expect('App\Settlements')
        ->not->toUse([
            'App\Orders\Models\Order',
            'App\Orders\Models\SellerOrder',
            'App\Orders\Models\OrderItem',
            'App\Orders\Services\OrderService',
            'App\Orders\Services\OrderItemsFeeLookupService',
        ]);
});

test('App\Settlements subsystem does not directly depend on Fees internal models or services', function () {
    expect('App\Settlements')
        ->not->toUse([
            'App\Fees\Models\FeeSchedule',
            'App\Fees\Models\FeeCommissionSlab',
            'App\Fees\Models\FeeClosingSlab',
            'App\Fees\Models\FeeShippingSlab',
            'App\Fees\Models\FeeCollectionRule',
            'App\Fees\Models\OrderItemFeeSnapshot',
            'App\Fees\Services\FeeCalculator',
            'App\Fees\Services\FeeScheduleService',
        ]);
});

test('App\Settlements subsystem does not directly depend on core User or SellerProfile models', function () {
    expect('App\Settlements')
        ->not->toUse([
            'App\Models\User',
            'App\Models\SellerProfile',
            'App\Models\SellerDocument',
        ]);
});

test('App\Orders and App\Fees subsystems do not depend on Settlements models or services', function () {
    expect('App\Orders')
        ->not->toUse([
            'App\Settlements\Models\SellerOrderSettlement',
            'App\Settlements\Models\SellerLedgerEntry',
            'App\Settlements\Models\SellerPayoutBatch',
            'App\Settlements\Models\SellerPayout',
            'App\Settlements\Services\SettlementService',
            'App\Settlements\Services\SellerLedgerService',
            'App\Settlements\Services\PayoutService',
        ]);

    expect('App\Fees')
        ->not->toUse([
            'App\Settlements\Models\SellerOrderSettlement',
            'App\Settlements\Models\SellerLedgerEntry',
            'App\Settlements\Models\SellerPayoutBatch',
            'App\Settlements\Models\SellerPayout',
            'App\Settlements\Services\SettlementService',
            'App\Settlements\Services\SellerLedgerService',
            'App\Settlements\Services\PayoutService',
        ]);
});

test('no class outside App\Settlements may use App\Settlements\Models', function () {
    expect('App\Settlements\Models')
        ->toOnlyBeUsedIn('App\Settlements');
});
