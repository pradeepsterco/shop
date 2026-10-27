<?php

test('App\Coupons subsystem does not depend on Catalog, Orders, Checkout, or Cart classes', function () {
    expect('App\Coupons')
        ->not->toUse([
            'App\Catalog',
            'App\Orders',
            'App\Checkout',
            'App\Models\User',
            'App\Models\Listing',
            'App\Models\Product',
            'App\Models\Cart',
            'App\Models\CartItem',
            'App\Services\CartService',
        ]);
});
