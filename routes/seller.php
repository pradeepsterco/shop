<?php

use App\Http\Controllers\SellerDocumentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::livewire('seller/apply', 'pages::seller.onboarding')->name('seller.apply');
    Route::get('seller/documents/{document}', [SellerDocumentController::class, 'show'])->name('seller.documents.show');

    Route::middleware(['ensure.seller'])->prefix('seller')->name('seller.')->group(function () {
        Route::livewire('/', 'pages::seller.dashboard')->name('dashboard');
        Route::livewire('orders', 'pages::seller.orders-index')->name('orders.index');
        Route::livewire('orders/{sellerOrder}', 'pages::seller.orders-show')->name('orders.show');
        Route::livewire('products', 'pages::seller.products-index')->name('products.index');
        Route::livewire('products/create', 'pages::seller.products-create')->name('products.create');
        Route::livewire('products/{product}/edit', 'pages::seller.products-edit')->name('products.edit');
        Route::livewire('fees/calculator', 'pages::seller.fee-calculator')->name('fees.calculator');
        Route::livewire('payments', 'pages::seller.payments-index')->name('payments.index');
        Route::livewire('payments/statement', 'pages::seller.payments-statement')->name('payments.statement');
        Route::livewire('profile', 'pages::seller.profile')->name('profile');
        Route::livewire('settings', 'pages::seller.settings')->name('settings');
    });
});
