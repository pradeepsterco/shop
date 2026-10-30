<?php

use App\Search\Contracts\SearchSuggestionsInterface;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

test('typing shows scope, category and product suggestions', function () {
    makeSearchableProduct(['title' => 'Galaxy Nova Phone', 'vertical' => 'Smartphone', 'category' => 'Mobiles', 'selling_price' => 2499900]);
    makeSearchableProduct(['title' => 'Phone Stand', 'vertical' => 'Phone Accessories', 'category' => 'Mobiles']);

    Livewire::test('search-box')
        ->set('query', 'phone')
        ->assertSee('Galaxy Nova Phone')
        ->assertSee('₹24,999')
        ->assertSee('Phone Accessories')
        ->assertSee(route('products.index', ['q' => 'phone', 'vertical' => 'smartphone']));
});

test('suggestions start at the minimum length and not for a prefilled query', function () {
    makeSearchableProduct(['title' => 'Galaxy Nova Phone']);

    Livewire::withQueryParams(['q' => 'galaxy'])
        ->test('search-box')
        ->assertSet('query', '')
        ->assertDontSee('Galaxy Nova Phone')
        ->set('query', 'g')
        ->assertDontSee('Galaxy Nova Phone')
        ->set('query', 'ga')
        ->assertSee('Galaxy Nova Phone');
});

test('cached suggestions hold no prices, so product prices stay live', function () {
    $product = makeSearchableProduct(['title' => 'Galaxy Nova Phone', 'selling_price' => 2499900]);
    $suggest = app(SearchSuggestionsInterface::class);

    expect($suggest->suggest('galaxy')['products'][0]['price_paise'])->toBe(2499900);

    $cached = Cache::get('search_suggest_'.md5('galaxy'));
    expect($cached)->toBeArray()->toHaveKeys(['scopes', 'categories', 'product_ids'])
        ->and(json_encode($cached))->not->toContain('2499900');

    $product->listings()->first()->update(['selling_price' => 1999900]);

    expect($suggest->suggest('galaxy')['products'][0]['price_paise'])->toBe(1999900);
});

test('category and vertical names that contain the query are suggested', function () {
    makeSearchableProduct(['title' => 'Mesh Runner', 'vertical' => 'Running Shoes', 'category' => 'Footwear']);

    $suggestions = app(SearchSuggestionsInterface::class)->suggest('foot');

    expect(collect($suggestions['categories'])->pluck('label')->all())->toBe(['Footwear']);
});
