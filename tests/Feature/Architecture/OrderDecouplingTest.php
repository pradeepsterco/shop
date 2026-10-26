<?php

test('App\Orders subsystem does not depend on Cart classes', function () {
    expect('App\Orders')
        ->not->toUse([
            'App\Models\Cart',
            'App\Models\CartItem',
            'App\Services\CartService',
        ]);
});

test('App\Orders subsystem does not depend on Checkout classes', function () {
    expect('App\Orders')
        ->not->toUse([
            'App\Checkout',
        ]);
});
