<?php

use App\Http\Controllers\SellerDocumentController;
use App\Returns\Http\Controllers\ReturnPhotoController;
use App\Returns\Http\Controllers\RtoPhotoController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('seller/apply', 'pages::seller.onboarding')->name('seller.apply');
    Route::get('seller/documents/{document}', [SellerDocumentController::class, 'show'])->name('seller.documents.show');

    // Fee Calculator: teaser accessible to any logged-in, verified account (with or without a seller profile)
    Route::prefix('seller')->name('seller.')->group(function () {
        Route::livewire('fees/calculator', 'pages::seller.fee-calculator')->name('fees.calculator');
    });

    // Orders & Payments: accessible to approved sellers AND suspended sellers (to fulfill orders and view payouts)
    Route::middleware(['ensure.seller.orders'])->prefix('seller')->name('seller.')->group(function () {
        Route::livewire('orders', 'pages::seller.orders-index')->name('orders.index');
        Route::livewire('orders/{sellerOrder}', 'pages::seller.orders-show')->name('orders.show');
        Route::get('returns/rto-reports/{report}/photos/{index}', [RtoPhotoController::class, 'seller'])->whereNumber('index')->name('returns.rto-photo');
        Route::get('returns/requests/{request}/photos/{kind}/{index}', [ReturnPhotoController::class, 'seller'])->whereIn('kind', ['customer', 'seller'])->whereNumber('index')->name('returns.request-photo');
        Route::livewire('payments', 'pages::seller.payments-index')->name('payments.index');
        Route::livewire('payments/statement', 'pages::seller.payments-statement')->name('payments.statement');
    });

    // Store operations requiring active approval (Overview, Products, Store Profile, Settings)
    Route::middleware(['ensure.seller'])->prefix('seller')->name('seller.')->group(function () {
        Route::livewire('/', 'pages::seller.dashboard')->name('dashboard');
        Route::livewire('products', 'pages::seller.products-index')->name('products.index');
        Route::livewire('products/create', 'pages::seller.products-create')->name('products.create');
        Route::livewire('products/{product}/edit', 'pages::seller.products-edit')->name('products.edit');
        Route::livewire('profile', 'pages::seller.profile')->name('profile');
        Route::livewire('settings', 'pages::seller.settings')->name('settings');
    });
});
