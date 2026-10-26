<?php

test('App\Fees subsystem does not directly depend on Orders internal models or services', function () {
    expect('App\Fees')
        ->not->toUse([
            'App\Orders\Models\Order',
            'App\Orders\Models\SellerOrder',
            'App\Orders\Models\OrderItem',
            'App\Orders\Services\OrderService',
            'App\Orders\Services\OrderItemsFeeLookupService',
        ]);
});

test('App\Fees subsystem does not depend on Cart or Checkout classes', function () {
    expect('App\Fees')
        ->not->toUse([
            'App\Models\Cart',
            'App\Models\CartItem',
            'App\Services\CartService',
            'App\Checkout',
        ]);
});

test('App\Fees subsystem does not directly query Catalog Vertical model', function () {
    expect('App\Fees')
        ->not->toUse([
            'App\Models\Vertical',
        ]);
});

test('App\Orders does not depend on Fees internal models or services', function () {
    expect('App\Orders')
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

test('no class outside App\Fees may use App\Fees\Models', function () {
    expect('App\Fees\Models')
        ->toOnlyBeUsedIn('App\Fees');
});
