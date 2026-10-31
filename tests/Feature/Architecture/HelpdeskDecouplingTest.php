<?php

test('App\Helpdesk module does not depend on Orders, Checkout, Cart, Shipping, Settlements, Coupons, Returns or Fees', function () {
    expect('App\Helpdesk')
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

test('other code only uses the Helpdesk services through the module', function () {
    expect('App\Helpdesk\Services')->toOnlyBeUsedIn(['App\Helpdesk', 'App\Livewire']);
});
