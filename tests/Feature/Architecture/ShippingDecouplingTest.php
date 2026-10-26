<?php

test('App\Shipping subsystem does not directly depend on Orders internal models or services', function () {
    expect('App\Shipping')
        ->not->toUse([
            'App\Orders\Models\Order',
            'App\Orders\Models\SellerOrder',
            'App\Orders\Models\OrderItem',
            'App\Orders\Models\SellerInvoiceSequence',
            'App\Orders\Services\OrderService',
            'App\Orders\Services\OrderItemsFeeLookupService',
        ]);
});

test('App\Shipping subsystem does not directly depend on Fees internal models or services', function () {
    expect('App\Shipping')
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

test('App\Shipping subsystem does not directly depend on Settlements internal models or services', function () {
    expect('App\Shipping')
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

test('App\Shipping subsystem does not directly depend on core User, SellerProfile, Listing, or ProductVariant models', function () {
    expect('App\Shipping')
        ->not->toUse([
            'App\Models\SellerProfile',
            'App\Models\SellerDocument',
            'App\Models\Listing',
            'App\Models\ProductVariant',
            'App\Models\Product',
        ]);
});

test('App\Orders, App\Fees, and App\Settlements subsystems do not depend on Shipping internal models or services', function () {
    expect('App\Orders')
        ->not->toUse([
            'App\Shipping\Models\Shipment',
            'App\Shipping\Models\ShipmentEvent',
            'App\Shipping\Models\SellerShippingProfile',
            'App\Shipping\Services\ShipmentService',
            'App\Shipping\Services\CarrierManager',
            'App\Shipping\Drivers\EkartCarrierDriver',
        ]);

    expect('App\Fees')
        ->not->toUse([
            'App\Shipping\Models\Shipment',
            'App\Shipping\Models\ShipmentEvent',
            'App\Shipping\Models\SellerShippingProfile',
            'App\Shipping\Services\ShipmentService',
            'App\Shipping\Services\CarrierManager',
        ]);

    expect('App\Settlements')
        ->not->toUse([
            'App\Shipping\Models\Shipment',
            'App\Shipping\Models\ShipmentEvent',
            'App\Shipping\Models\SellerShippingProfile',
            'App\Shipping\Services\ShipmentService',
            'App\Shipping\Services\CarrierManager',
        ]);
});

test('no class outside App\Shipping may use App\Shipping\Models', function () {
    expect('App\Shipping\Models')
        ->toOnlyBeUsedIn('App\Shipping');
});
