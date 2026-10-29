<?php

test('App\Returns subsystem does not directly query foreign models', function () {
    expect('App\Returns')
        ->not->toUse([
            'App\Orders\Models\Order',
            'App\Orders\Models\SellerOrder',
            'App\Orders\Models\OrderItem',
            'App\Shipping\Models\Shipment',
            'App\Shipping\Models\ShipmentEvent',
            'App\Settlements\Models\SellerOrderSettlement',
            'App\Settlements\Models\SellerLedgerEntry',
        ]);
});

test('App\Settlements subsystem does not depend on App\Shipping', function () {
    expect('App\Settlements')
        ->not->toUse([
            'App\Shipping',
        ]);
});
