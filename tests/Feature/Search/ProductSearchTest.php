<?php

use App\Search\Contracts\ProductSearchInterface;
use App\Search\Contracts\SearchEngineInterface;
use App\Search\Contracts\SearchIndexerInterface;
use App\Search\Engines\FakeEngine;
use Illuminate\Support\Facades\Log;

function searchIds(string $query): array
{
    return app(ProductSearchInterface::class)->search($query)->productIds;
}

test('every word must match and title matches rank first', function () {
    $titleMatch = makeSearchableProduct(['title' => 'Galaxy Phone Case']);
    $keywordMatch = makeSearchableProduct(['title' => 'Nova Flip', 'brand' => 'Galaxy Works', 'highlights' => ['Phone grade glass']]);
    makeSearchableProduct(['title' => 'Galaxy Tablet']);

    expect(searchIds('galaxy phone'))->toBe([$titleMatch->id, $keywordMatch->id]);
});

test('words match from the start of a word, not the middle', function () {
    $phone = makeSearchableProduct(['title' => 'Nova Phone']);
    makeSearchableProduct(['title' => 'Saxophone Stand']);
    $tee = makeSearchableProduct(['title' => 'Cotton T-Shirt']);

    expect(searchIds('pho'))->toBe([$phone->id])
        ->and(searchIds('shirt'))->toBe([$tee->id]);
});

test('queries are case and punctuation insensitive', function () {
    $product = makeSearchableProduct(['title' => 'Galaxy Nova Phone']);

    expect(searchIds('  GALAXY,  nova!! '))->toBe([$product->id])
        ->and(app(ProductSearchInterface::class)->search('  GALAXY,  nova!! ')->query)->toBe('galaxy nova');
});

test('products can be found by UPID', function () {
    $product = makeSearchableProduct(['title' => 'Galaxy Nova Phone']);
    $upid = strtolower($product->variants()->first()->upid);

    expect(searchIds($upid))->toBe([$product->id]);
});

test('an exact title phrase outranks scattered matches', function () {
    $scattered = makeSearchableProduct(['title' => 'Board Puzzle Game']);
    $phrase = makeSearchableProduct(['title' => 'Classic Board Game']);

    expect(searchIds('board game'))->toBe([$phrase->id, $scattered->id]);
});

test('blank queries return nothing', function () {
    makeSearchableProduct(['title' => 'Galaxy Nova Phone']);

    expect(app(ProductSearchInterface::class)->search(' !! ')->isEmpty())->toBeTrue();
});

test('when the engine fails search falls back to title and brand matching', function () {
    config(['search.engine' => 'fake']);
    $byTitle = makeSearchableProduct(['title' => 'Galaxy Nova Phone']);
    $byBrand = makeSearchableProduct(['title' => 'Nova Flip', 'brand' => 'Galaxy Works']);
    makeSearchableProduct(['title' => 'Something Else']);

    $engine = app(SearchEngineInterface::class);
    expect($engine)->toBeInstanceOf(FakeEngine::class);
    $engine->failSearches = true;

    Log::spy();
    $result = app(ProductSearchInterface::class)->search('galaxy');

    expect($result->fromFallback)->toBeTrue()
        ->and($result->productIds)->toEqualCanonicalizing([$byTitle->id, $byBrand->id]);
    Log::shouldHaveReceived('warning')->once();
});

test('the fake engine receives documents from the indexer', function () {
    config(['search.engine' => 'fake']);
    $product = makeSearchableProduct(['title' => 'Galaxy Nova Phone']);

    app(SearchIndexerInterface::class)->index($product->id);

    expect(app(SearchEngineInterface::class)->documents)->toHaveKey($product->id)
        ->and(searchIds('nova'))->toBe([$product->id]);
});
