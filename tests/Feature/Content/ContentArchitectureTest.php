<?php

test('App\Content subsystem does not directly depend on Catalog models', function () {
    expect('App\Content')
        ->not->toUse([
            'App\Models\Product',
            'App\Models\ProductVariant',
            'App\Models\ProductImage',
            'App\Models\Category',
            'App\Models\Brand',
            'App\Models\Vertical',
            'App\Models\VerticalAttribute',
        ]);
});

test('App\Catalog subsystem does not depend on App\Content subsystem', function () {
    expect('App\Catalog')
        ->not->toUse([
            'App\Content',
        ]);
});
