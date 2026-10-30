<?php

test('App\Search module does not depend on Orders, Checkout, Cart, Shipping, Settlements, Coupons, Returns or Fees', function () {
    expect('App\Search')
        ->not->toUse([
            'App\Orders',
            'App\Checkout',
            'App\Shipping',
            'App\Settlements',
            'App\Coupons',
            'App\Returns',
            'App\Fees',
            'App\Models\Cart',
            'App\Models\CartItem',
            'App\Services\CartService',
        ]);
});

test('other code only uses the Search contracts and DTOs', function () {
    expect('App\Search\Engines')->toOnlyBeUsedIn('App\Search');
    expect('App\Search\Services')->toOnlyBeUsedIn('App\Search');
    expect('App\Search\Jobs')->toOnlyBeUsedIn('App\Search');
    expect('App\Search\Models')->toOnlyBeUsedIn('App\Search');
});
