<?php

use Livewire\Livewire;

test('the products page shows only products matching q, best match first', function () {
    $keywordMatch = makeSearchableProduct(['title' => 'Nova Flip', 'highlights' => ['Galaxy grade glass']]);
    $titleMatch = makeSearchableProduct(['title' => 'Galaxy Nova Phone']);
    makeSearchableProduct(['title' => 'Cotton Shirt']);

    $component = Livewire::withQueryParams(['q' => 'galaxy'])
        ->test('pages::storefront.product-index')
        ->assertSee('Results for "galaxy"', false)
        ->assertSee('Relevance')
        ->assertDontSee('Cotton Shirt');

    expect($component->instance()->products->pluck('id')->all())->toBe([$titleMatch->id, $keywordMatch->id]);
});

test('search keeps in-stock products first', function () {
    $outOfStock = makeSearchableProduct(['title' => 'Galaxy Nova Phone', 'stock' => 0]);
    $inStock = makeSearchableProduct(['title' => 'Nova Flip', 'highlights' => ['Galaxy grade glass']]);

    $component = Livewire::withQueryParams(['q' => 'galaxy'])->test('pages::storefront.product-index');

    expect($component->instance()->products->pluck('id')->all())->toBe([$inStock->id, $outOfStock->id]);
});

test('search combines with filters and other sorts', function () {
    $cheap = makeSearchableProduct(['title' => 'Galaxy Lite Phone', 'brand' => 'Samsung', 'selling_price' => 50000]);
    $pricey = makeSearchableProduct(['title' => 'Galaxy Pro Phone', 'brand' => 'Samsung', 'selling_price' => 90000]);
    makeSearchableProduct(['title' => 'Galaxy Clone Phone', 'brand' => 'Other']);

    $component = Livewire::withQueryParams(['q' => 'galaxy', 'brands' => ['samsung'], 'sort' => 'price_desc'])
        ->test('pages::storefront.product-index');

    expect($component->instance()->products->pluck('id')->all())->toBe([$pricey->id, $cheap->id])
        ->and($component->instance()->availableBrands->pluck('slug')->sort()->values()->all())->toBe(['other', 'samsung']);
});

test('brands are limited to those with matching products while searching', function () {
    makeSearchableProduct(['title' => 'Galaxy Nova Phone', 'brand' => 'Samsung']);
    makeSearchableProduct(['title' => 'Cotton Shirt', 'brand' => 'Levis']);

    $component = Livewire::withQueryParams(['q' => 'galaxy'])->test('pages::storefront.product-index');

    expect($component->instance()->availableBrands->pluck('slug')->all())->toBe(['samsung']);
});

test('no results shows a search empty state and clearing search shows everything', function () {
    makeSearchableProduct(['title' => 'Galaxy Nova Phone']);

    Livewire::withQueryParams(['q' => 'xyzzy'])
        ->test('pages::storefront.product-index')
        ->assertSee('No results for "xyzzy"', false)
        ->assertSee('Check the spelling')
        ->call('clearSearch')
        ->assertSet('q', '')
        ->assertSee('Galaxy Nova Phone');
});

test('the storefront header renders the search box', function () {
    makeSearchableProduct(['title' => 'Galaxy Nova Phone']);

    $this->get(route('products.index', ['q' => 'galaxy']))
        ->assertOk()
        ->assertSee('role="search"', false)
        ->assertSee('Search for products, brands and more')
        ->assertSee('Galaxy Nova Phone');
});
