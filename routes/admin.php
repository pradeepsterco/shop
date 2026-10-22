<?php

use App\Http\Controllers\Admin\AdminSellerDocumentController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware('guest:admin')->group(function () {
    Route::livewire('login', 'pages::admin.login')->name('login');
});

Route::middleware('auth:admin')->group(function () {
    Route::livewire('/', 'pages::admin.dashboard')->name('dashboard');
    Route::livewire('customers', 'pages::admin.customers')->name('customers');
    Route::livewire('customers/{user}', 'pages::admin.customer-details')->name('customers.show');
    Route::livewire('sellers', 'pages::admin.sellers-index')->name('sellers.index');
    Route::livewire('sellers/{sellerProfile}', 'pages::admin.sellers-show')->name('sellers.show');
    Route::get('sellers/documents/{document}', [AdminSellerDocumentController::class, 'show'])->name('sellers.documents.show');

    // Catalog & Brands
    Route::livewire('catalog/categories', 'pages::admin.catalog.categories')->name('catalog.categories');
    Route::livewire('catalog/verticals', 'pages::admin.catalog.verticals')->name('catalog.verticals');
    Route::livewire('catalog/verticals/{vertical}/attributes', 'pages::admin.catalog.vertical-attributes')->name('catalog.vertical-attributes');
    Route::livewire('catalog/brands', 'pages::admin.catalog.brands')->name('catalog.brands');
    Route::livewire('catalog/products', 'pages::admin.catalog.products')->name('catalog.products');

    Route::livewire('profile', 'pages::admin.profile')->name('profile');

    Route::post('logout', function (Request $request) {
        Auth::guard('admin')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    })->name('logout');
});
