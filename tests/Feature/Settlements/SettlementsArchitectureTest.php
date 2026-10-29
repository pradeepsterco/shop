<?php

test('App\Settlements subsystem does not directly depend on App\Shipping', function () {
    expect('App\Settlements')
        ->not->toUse([
            'App\Shipping',
        ]);
});
