<?php

use App\Http\Controllers\Admin\AdminSellerDocumentController;
use App\Http\Middleware\AuthenticateAdminSession;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureAdminPasswordChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::middleware('guest:admin')->group(function () {
    Route::livewire('login', 'pages::admin.login')->name('login');
    Route::livewire('set-password/{token}', 'pages::admin.auth.set-password')->name('password.set');
});

Route::middleware(['auth:admin', EnsureAdminIsActive::class, AuthenticateAdminSession::class, EnsureAdminPasswordChanged::class])->group(function () {
    Route::livewire('/', 'pages::admin.dashboard')->name('dashboard');
    Route::livewire('force-change-password', 'pages::admin.auth.force-change-password')->name('password.force-change');

    // Customers
    Route::livewire('customers', 'pages::admin.customers')->name('customers')->middleware('can:customers.view');
    Route::livewire('customers/{user}', 'pages::admin.customer-details')->name('customers.show')->middleware('can:customers.view');

    // Sellers & KYC
    Route::livewire('sellers', 'pages::admin.sellers-index')->name('sellers.index')->middleware('can:sellers.view');
    Route::livewire('sellers/{sellerProfile}', 'pages::admin.sellers-show')->name('sellers.show')->middleware('can:sellers.view');
    Route::get('sellers/documents/{document}', [AdminSellerDocumentController::class, 'show'])->name('sellers.documents.show')->middleware('can:sellers.kyc');

    // Catalog & Brands
    Route::livewire('catalog/categories', 'pages::admin.catalog.categories')->name('catalog.categories')->middleware('can:catalog.categories');
    Route::livewire('catalog/verticals', 'pages::admin.catalog.verticals')->name('catalog.verticals')->middleware('can:catalog.verticals');
    Route::livewire('catalog/verticals/{vertical}/attributes', 'pages::admin.catalog.vertical-attributes')->name('catalog.vertical-attributes')->middleware('can:catalog.attributes');
    Route::livewire('catalog/brands', 'pages::admin.catalog.brands')->name('catalog.brands')->middleware('can:catalog.brands.manage');
    Route::livewire('catalog/products', 'pages::admin.catalog.products')->name('catalog.products')->middleware('can:catalog.products.view');

    // Staff & Roles Management
    Route::livewire('staff', 'pages::admin.staff')->name('staff')->middleware('can:admins.view');
    Route::livewire('roles', 'pages::admin.roles')->name('roles')->middleware('can:roles.manage');
    Route::livewire('activity-logs', 'pages::admin.activity-logs')->name('activity-logs')->middleware('can:audit.view');

    Route::livewire('profile', 'pages::admin.profile')->name('profile');

    Route::post('logout', function (Request $request) {
        Auth::guard('admin')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    })->name('logout');
});
