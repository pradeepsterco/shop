<?php

use App\Models\Vertical;
use Livewire\Livewire;

function addColourAttribute(string $verticalSlug): void
{
    Vertical::where('slug', $verticalSlug)->firstOrFail()->attributes()->create([
        'name' => 'Colour',
        'code' => 'colour',
        'type' => 'select',
        'is_variant' => true,
        'options' => [
            ['label' => 'Red', 'value' => 'red', 'is_active' => true],
            ['label' => 'Blue', 'value' => 'blue', 'is_active' => true],
            ['label' => 'Retired Green', 'value' => 'green', 'is_active' => false],
        ],
    ]);
}

test('previewing a draft returns its count without changing the applied filters', function () {
    makeSearchableProduct(['title' => 'Galaxy Phone', 'brand' => 'Samsung']);
    makeSearchableProduct(['title' => 'Pixel Phone', 'brand' => 'Google']);
    makeSearchableProduct(['title' => 'Pixel Watch', 'brand' => 'Google', 'stock' => 0]);

    $component = Livewire::test('pages::storefront.product-index');

    expect($component->instance()->previewFilters(['brands' => ['google']])['count'])->toBe(2)
        ->and($component->instance()->previewFilters(['brands' => ['google'], 'in_stock' => true])['count'])->toBe(1);

    $component->assertSet('brands', [])->assertSet('in_stock', false);
    expect($component->instance()->products->total())->toBe(3);
});

test('preview returns the variant filters of the drafted vertical, without inactive options', function () {
    makeSearchableProduct(['title' => 'Red Phone', 'vertical' => 'Smartphone', 'variant_attributes' => ['colour' => 'red']]);
    makeSearchableProduct(['title' => 'Blue Phone', 'vertical' => 'Smartphone', 'variant_attributes' => ['colour' => 'blue']]);
    makeSearchableProduct(['title' => 'Mesh Runner', 'vertical' => 'Running Shoes', 'category' => 'Footwear']);
    addColourAttribute('smartphone');

    $component = Livewire::test('pages::storefront.product-index');

    expect($component->instance()->previewFilters([])['attributes'])->toBe([]);

    $preview = $component->instance()->previewFilters(['vertical' => 'smartphone', 'attrs' => ['colour' => ['red']]]);

    expect($preview['count'])->toBe(1)
        ->and($preview['attributes'])->toBe([[
            'code' => 'colour',
            'name' => 'Colour',
            'options' => [['value' => 'red', 'label' => 'Red'], ['value' => 'blue', 'label' => 'Blue']],
        ]]);
});

test('applying a draft sets every filter in one request and resets to page one', function () {
    $red = makeSearchableProduct(['title' => 'Red Phone', 'brand' => 'Samsung', 'variant_attributes' => ['colour' => 'red'], 'selling_price' => 90000]);
    makeSearchableProduct(['title' => 'Blue Phone', 'brand' => 'Samsung', 'variant_attributes' => ['colour' => 'blue'], 'selling_price' => 90000]);
    makeSearchableProduct(['title' => 'Cheap Red Phone', 'brand' => 'Samsung', 'variant_attributes' => ['colour' => 'red'], 'selling_price' => 20000]);
    addColourAttribute('smartphone');

    $component = Livewire::withQueryParams(['page' => 2])
        ->test('pages::storefront.product-index')
        ->call('applyFilters', [
            'vertical' => 'smartphone',
            'brands' => ['samsung'],
            'min_price' => '500',
            'in_stock' => true,
            'attrs' => ['colour' => ['red']],
        ])
        ->assertSet('vertical', 'smartphone')
        ->assertSet('brands', ['samsung'])
        ->assertSet('min_price', '500')
        ->assertSet('in_stock', true)
        ->assertSet('attrs', ['colour' => ['red']])
        ->assertSet('paginators.page', 1);

    expect($component->instance()->products->pluck('id')->all())->toBe([$red->id])
        ->and($component->instance()->activeFilterCount)->toBe(5);
});

test('applied drafts are sanitised', function () {
    makeSearchableProduct(['title' => 'Galaxy Phone', 'brand' => 'Samsung']);

    Livewire::test('pages::storefront.product-index')
        ->call('applyFilters', [
            'category' => ['not-a-string'],
            'brands' => ['samsung', 5, ['nested'], ' ', 'samsung'],
            'min_price' => 'abc',
            'max_price' => '-10',
            'min_discount' => '0',
            'in_stock' => 'yes',
            'attrs' => ['colour' => ['red', ['x']], 7 => ['ignored'], 'size' => []],
        ])
        ->assertSet('category', '')
        ->assertSet('brands', ['samsung'])
        ->assertSet('min_price', null)
        ->assertSet('max_price', null)
        ->assertSet('min_discount', null)
        ->assertSet('in_stock', true)
        ->assertSet('attrs', ['colour' => ['red']]);
});

test('preview counts stay within the search results', function () {
    makeSearchableProduct(['title' => 'Galaxy Phone', 'brand' => 'Samsung']);
    makeSearchableProduct(['title' => 'Galaxy Tab', 'brand' => 'Samsung', 'stock' => 0]);
    makeSearchableProduct(['title' => 'Pixel Phone', 'brand' => 'Samsung']);

    $component = Livewire::withQueryParams(['q' => 'galaxy'])->test('pages::storefront.product-index');

    expect($component->instance()->previewFilters(['brands' => ['samsung']])['count'])->toBe(2)
        ->and($component->instance()->previewFilters(['brands' => ['samsung'], 'in_stock' => true])['count'])->toBe(1);
});

test('the page renders the mobile bar and sheets alongside the desktop sidebar', function () {
    makeSearchableProduct(['title' => 'Galaxy Phone', 'brand' => 'Samsung']);

    Livewire::withQueryParams(['brands' => ['samsung'], 'in_stock' => true])
        ->test('pages::storefront.product-index')
        ->assertSee('aria-labelledby="filter-sheet-title"', false)
        ->assertSee('aria-labelledby="sort-sheet-title"', false)
        ->assertSee('Price: Low to High')
        ->assertSee('Under ₹500')
        ->assertSet('activeFilterCount', 2);
});

test('desktop price presets still filter', function () {
    makeSearchableProduct(['title' => 'Cheap Phone', 'selling_price' => 40000]);
    $mid = makeSearchableProduct(['title' => 'Mid Phone', 'selling_price' => 70000]);

    $component = Livewire::test('pages::storefront.product-index')
        ->call('setPricePreset', 500, 1000)
        ->assertSet('min_price', '500')
        ->assertSet('max_price', '1000');

    expect($component->instance()->products->pluck('id')->all())->toBe([$mid->id]);
});
