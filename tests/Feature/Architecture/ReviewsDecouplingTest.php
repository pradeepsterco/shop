<?php

test('App\Reviews module does not depend on Orders, Cart, Shipping, Settlements, Coupons, Returns, or Fees', function () {
    expect('App\Reviews')
        ->not->toUse([
            'App\Orders',
            'App\Shipping',
            'App\Settlements',
            'App\Coupons',
            'App\Returns',
            'App\Fees',
            'App\Checkout',
            'App\Models\Cart',
            'App\Models\CartItem',
            'App\Services\CartService',
            'App\Models\Listing',
            'App\Models\Product',
            'App\Models\ProductVariant',
        ]);
});
