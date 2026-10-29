<?php

use App\Content\Models\ContentPage;
use App\Content\Services\PageResolver;
use App\Http\Controllers\Admin\AdminSellerDocumentController;
use App\Http\Middleware\AuthenticateAdminSession;
use App\Http\Middleware\EnsureAdminIsActive;
use App\Http\Middleware\EnsureAdminPasswordChanged;
use App\Returns\Http\Controllers\ReturnPhotoController;
use App\Returns\Http\Controllers\RtoPhotoController;
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

    // Reviews & Moderation
    Route::livewire('reviews', 'pages::admin.reviews-index')->name('reviews.index')->middleware('can:reviews.view');

    // Orders
    Route::livewire('orders', 'pages::admin.orders-index')->name('orders.index')->middleware('can:orders.view');
    Route::livewire('orders/{order}', 'pages::admin.orders-show')->name('orders.show')->middleware('can:orders.view');

    // Fees & Commissions
    Route::livewire('fees', 'pages::admin.fees-index')->name('fees.index')->middleware('can:fees.view');
    Route::livewire('fees/create', 'pages::admin.fees-edit')->name('fees.create')->middleware('can:fees.manage');
    Route::livewire('fees/{feeSchedule}', 'pages::admin.fees-show')->name('fees.show')->middleware('can:fees.view');
    Route::livewire('fees/{feeSchedule}/edit', 'pages::admin.fees-edit')->name('fees.edit')->middleware('can:fees.manage');

    // Settlements & Payouts
    Route::livewire('settlements', 'pages::admin.settlements-index')->name('settlements.index')->middleware('can:settlements.view');
    Route::livewire('settlements/reports', 'pages::admin.settlements-reports')->name('settlements.reports')->middleware('can:settlements.view');
    Route::livewire('payouts', 'pages::admin.payouts-index')->name('payouts.index')->middleware('can:payouts.view');

    // Shipping & Shipments
    Route::livewire('shipping/settings', 'pages::admin.shipping-settings')->name('shipping.settings')->middleware('can:shipping.view');
    Route::livewire('shipping/shipments', 'pages::admin.shipments-index')->name('shipments.index')->middleware('can:shipping.view');
    Route::livewire('shipping/shipments/{shipment}', 'pages::admin.shipments-show')->name('shipments.show')->middleware('can:shipping.view');

    // Returns & Claims
    Route::livewire('returns', 'pages::admin.returns-index')->name('returns.index')->middleware('can:returns.view');
    Route::get('returns/requests/{request}/photos/{kind}/{index}', [ReturnPhotoController::class, 'admin'])->whereIn('kind', ['customer', 'seller'])->whereNumber('index')->name('returns.request-photo')->middleware('can:returns.view');
    Route::livewire('refunds', 'pages::admin.refunds-index')->name('refunds.index')->middleware('can:returns.view');
    Route::get('returns/rto-reports/{report}/photos/{index}', [RtoPhotoController::class, 'admin'])->whereNumber('index')->name('returns.rto-photo')->middleware('can:returns.view');

    // Staff & Roles Management
    Route::livewire('staff', 'pages::admin.staff')->name('staff')->middleware('can:admins.view');
    Route::livewire('roles', 'pages::admin.roles')->name('roles')->middleware('can:roles.manage');
    Route::livewire('activity-logs', 'pages::admin.activity-logs')->name('activity-logs')->middleware('can:audit.view');

    // Branding & Settings
    Route::livewire('settings/branding', 'pages::admin.settings.branding')->name('settings.branding')->middleware('can:branding.view');

    // Content & Pages
    Route::livewire('pages', 'pages::admin.content.pages-index')->name('pages.index')->middleware('can:pages.view');
    Route::livewire('pages/{page}/builder', 'pages::admin.content.page-builder')->name('pages.builder')->middleware('can:pages.manage');
    Route::get('pages/{page}/preview', function (ContentPage $page, Request $request, PageResolver $resolver) {
        if (! $request->hasValidSignature()) {
            abort(403, 'Invalid or expired preview signature.');
        }

        $resolvedPage = $resolver->resolve($page, 'preview');

        return response()->view('pages.admin.content.preview', [
            'page' => $page,
            'resolvedPage' => $resolvedPage,
        ])->header('X-Robots-Tag', 'noindex, nofollow');
    })->name('pages.preview')->middleware('can:pages.view');

    // Coupons & Promotions
    Route::livewire('coupons', 'pages::admin.coupons.index')->name('coupons.index')->middleware('can:coupons.view');
    Route::livewire('coupons/create', 'pages::admin.coupons.create')->name('coupons.create')->middleware('can:coupons.manage');
    Route::livewire('coupons/{coupon}/edit', 'pages::admin.coupons.edit')->name('coupons.edit')->middleware('can:coupons.manage');

    Route::livewire('profile', 'pages::admin.profile')->name('profile');

    Route::post('logout', function (Request $request) {
        Auth::guard('admin')->logout();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    })->name('logout');
});
