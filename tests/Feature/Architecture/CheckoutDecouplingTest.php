<?php

test('App\Checkout subsystem does not depend on Cart classes', function () {
    expect('App\Checkout')
        ->not->toUse([
            'App\Models\Cart',
            'App\Models\CartItem',
            'App\Services\CartService',
        ]);
});
