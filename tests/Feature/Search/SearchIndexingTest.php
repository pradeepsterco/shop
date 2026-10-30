<?php

use App\Enums\ListingStatus;
use App\Enums\ProductQcStatus;
use App\Models\Admin;
use App\Models\Listing;
use App\Search\Contracts\SearchIndexerInterface;
use App\Search\Jobs\ReindexProduct;
use App\Search\Models\SearchDocument;
use Illuminate\Support\Facades\Queue;

test('an approved product with an active listing is indexed', function () {
    $product = makeSearchableProduct([
        'title' => 'Galaxy Nova Phone',
        'brand' => 'Samsung',
        'highlights' => ['Super AMOLED display'],
        'variant_attributes' => ['colour' => 'midnight-blue'],
    ]);

    $document = SearchDocument::find($product->id);

    expect($document)->not->toBeNull()
        ->and($document->title)->toBe('galaxy nova phone')
        ->and($document->keywords)->toContain('samsung', 'smartphone', 'electronics', 'super amoled display', 'midnight-blue')
        ->and($document->in_stock)->toBeTrue()
        ->and($document->document['min_price'])->toBe(120000)
        ->and($document->document['attrs'])->toBe(['colour' => ['midnight-blue']]);
});

test('variant values use the vertical attribute option label', function () {
    $product = makeSearchableProduct(['title' => 'Basic Tee', 'vertical' => 'T-Shirts', 'variant_attributes' => ['size' => 'xl']]);

    $product->vertical->attributes()->create([
        'name' => 'Size',
        'code' => 'size',
        'type' => 'select',
        'options' => [['label' => 'Extra Large', 'value' => 'xl', 'is_active' => true]],
        'is_variant' => true,
    ]);

    app(SearchIndexerInterface::class)->index($product->id);

    expect(SearchDocument::find($product->id)->keywords)->toContain('extra large');
});

test('price and stock changes reach the index through the buy box refresh', function () {
    $product = makeSearchableProduct(['title' => 'Galaxy Nova Phone', 'stock' => 5]);
    $listing = Listing::query()->whereHas('variant', fn ($q) => $q->where('product_id', $product->id))->firstOrFail();

    $listing->update(['stock' => 0, 'selling_price' => 99900]);

    $document = SearchDocument::find($product->id);
    expect($document->in_stock)->toBeFalse()
        ->and($document->document['min_price'])->toBe(99900);
});

test('a product that loses its buy box or approval is removed from the index', function () {
    $product = makeSearchableProduct(['title' => 'Galaxy Nova Phone']);
    expect(SearchDocument::find($product->id))->not->toBeNull();

    Listing::query()->whereHas('variant', fn ($q) => $q->where('product_id', $product->id))
        ->firstOrFail()
        ->update(['status' => ListingStatus::Inactive]);
    expect(SearchDocument::find($product->id))->toBeNull();

    $other = makeSearchableProduct(['title' => 'Galaxy Ultra Phone']);
    $other->reject(Admin::factory()->create(), 'Blurry images');
    expect(SearchDocument::find($other->id))->toBeNull();
});

test('approving pending title changes reindexes the new title', function () {
    $product = makeSearchableProduct(['title' => 'Old Phone Name']);
    $product->update(['qc_status' => ProductQcStatus::QcPending, 'pending_changes' => ['title' => 'Brand New Phone Name']]);

    $product->fresh()->approve(Admin::factory()->create());

    expect(SearchDocument::find($product->id)->title)->toBe('brand new phone name');
});

test('reindexing is queued after commit and collapses repeated changes', function () {
    Queue::fake();

    $product = makeSearchableProduct(['title' => 'Galaxy Nova Phone']);
    $product->update(['title' => 'Galaxy Nova Phone 2']);
    $product->update(['title' => 'Galaxy Nova Phone 3']);

    Queue::assertPushed(ReindexProduct::class, fn (ReindexProduct $job) => $job->productId === $product->id);
    expect((new ReindexProduct($product->id))->uniqueId())->toBe((string) $product->id);
});

test('indexing can be switched off', function () {
    config(['search.indexing_enabled' => false]);
    Queue::fake();

    makeSearchableProduct(['title' => 'Galaxy Nova Phone']);

    Queue::assertNotPushed(ReindexProduct::class);
});

test('search:reindex rebuilds the index and --fresh drops stale documents', function () {
    config(['search.indexing_enabled' => false]);

    $first = makeSearchableProduct(['title' => 'Galaxy Nova Phone']);
    $second = makeSearchableProduct(['title' => 'Galaxy Ultra Phone']);
    expect(SearchDocument::count())->toBe(0);

    $this->artisan('search:reindex')
        ->expectsOutputToContain('Indexed 2 searchable products.')
        ->assertSuccessful();

    expect(SearchDocument::pluck('product_id')->sort()->values()->all())->toBe([$first->id, $second->id]);

    SearchDocument::query()->whereKey($first->id)->update(['title' => 'stale']);
    $this->artisan('search:reindex', ['--fresh' => true])->assertSuccessful();

    expect(SearchDocument::find($first->id)->title)->toBe('galaxy nova phone');
});
