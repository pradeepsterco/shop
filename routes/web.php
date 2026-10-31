<?php

use App\Checkout\Services\CheckoutIntentService;
use App\Helpdesk\Http\Controllers\TicketAttachmentController;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::livewire('/', 'pages::storefront.home-page')->name('home');
Route::livewire('pages/{slug}', 'pages::storefront.static-page')->name('content.page');
Route::livewire('grievance-officer', 'pages::storefront.grievance-officer')->name('grievance-officer');
Route::livewire('sellers/{slug}', 'pages::storefront.seller-show')->name('sellers.show');

Route::livewire('products', 'pages::storefront.product-index')->name('products.index');
Route::livewire('{slug}/p/{upid}', 'pages::storefront.product-show')->name('product.show');

Route::livewire('cart', 'pages::storefront.cart')->name('cart');

Route::get('checkout/resume-buy-now', function (Request $request, CheckoutIntentService $checkoutService) {
    $listingId = $request->session()->pull('pending_buy_now_listing_id');
    if (! $listingId) {
        return redirect()->route('cart');
    }

    $user = $request->user();
    if (! ($user instanceof User)) {
        return redirect()->route('login');
    }

    try {
        $intent = $checkoutService->createOrReuseBuyNowIntent($user, (int) $listingId);

        return redirect()->route('checkout.show', ['intent' => $intent->id]);
    } catch (DomainException $e) {
        return redirect()->route('cart')->with('error', $e->getMessage());
    }
})->middleware('auth')->name('checkout.resume-buy-now');

Route::livewire('checkout/{intent}', 'pages::storefront.checkout-summary')
    ->middleware('auth')
    ->name('checkout.show');

Route::middleware('auth')->group(function () {
    Route::livewire('orders', 'pages::storefront.order-index')->name('orders.index');
    Route::livewire('orders/{order}/success', 'pages::storefront.order-success')->name('orders.success');
    Route::livewire('orders/{order}', 'pages::storefront.order-show')->name('orders.show');
    Route::livewire('addresses', 'pages::storefront.address-index')->name('addresses.index');

    Route::livewire('support', 'pages::storefront.support-index')->name('support.index');
    Route::livewire('support/new', 'pages::storefront.support-create')->name('support.create');
    Route::livewire('support/{ticket}', 'pages::storefront.support-show')->name('support.show');
    Route::get('support/{ticket}/messages/{message}/attachments/{index}', [TicketAttachmentController::class, 'requester'])->whereNumber(['message', 'index'])->name('support.attachment');
});

Route::livewire('welcome/{token}', 'pages::auth.welcome')->name('welcome');

Route::livewire('force-change-password', 'pages::auth.force-change-password')
    ->name('password.force-change')
    ->middleware('auth');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});
require __DIR__.'/settings.php';
require __DIR__.'/seller.php';
