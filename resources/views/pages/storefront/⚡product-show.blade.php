<?php

namespace App\Livewire\Storefront;

use App\Checkout\Services\CheckoutIntentService;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\CartService;
use App\Services\ProductBuyBoxService;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.storefront')] class extends Component {
    public string $slug;
    public string $upid;

    public function mount(string $slug, string $upid): void
    {
        $this->slug = $slug;
        $this->upid = $upid;

        if (! $this->product || ! $this->currentVariant) {
            abort(404, 'Product or variant not found');
        }
    }

    #[Computed]
    public function product(): ?Product
    {
        return Product::where('slug', $this->slug)
            ->where('qc_status', ProductQcStatus::Approved)
            ->with([
                'brand',
                'vertical.attributes',
                'images' => fn ($q) => $q->where('status', ProductImageStatus::Live)->orderBy('sort_order'),
                'variants' => fn ($q) => $q->where('qc_status', VariantQcStatus::Approved)->with([
                    'images' => fn ($img) => $img->where('status', ProductImageStatus::Live),
                    'listings' => fn ($q) => $q->where('status', ListingStatus::Active)->whereHas('sellerProfile', fn ($s) => $s->where('status', SellerStatus::Approved)),
                ]),
                'buyBoxListing.sellerProfile',
            ])
            ->first();
    }

    #[Computed]
    public function currentVariant(): ?ProductVariant
    {
        return ProductVariant::where('upid', $this->upid)
            ->where('product_id', $this->product?->id)
            ->where('qc_status', VariantQcStatus::Approved)
            ->with([
                'images' => fn ($img) => $img->where('status', ProductImageStatus::Live),
                'listings' => fn ($q) => $q->where('status', ListingStatus::Active)->whereHas('sellerProfile', fn ($s) => $s->where('status', SellerStatus::Approved)),
            ])
            ->first();
    }

    #[Computed]
    public function bestListing(): ?Listing
    {
        if (! $this->currentVariant) {
            return null;
        }

        return app(ProductBuyBoxService::class)->resolveForVariant($this->currentVariant);
    }

    #[Computed]
    public function isInCart(): bool
    {
        if (! $this->bestListing) {
            return false;
        }

        return app(CartService::class)->hasListing($this->bestListing->id);
    }

    #[Computed]
    public function isOwnListing(): bool
    {
        $user = auth()->user();
        if (! $user || ! $user->sellerProfile || ! $this->bestListing) {
            return false;
        }

        return $this->bestListing->seller_profile_id === $user->sellerProfile->id;
    }

    /**
     * Build multi-attribute selector matrix.
     * Unavailable combinations are marked disabled.
     *
     * @return array<string, array<string, mixed>>
     */
    #[Computed]
    public function attributeMatrix(): array
    {
        if (! $this->product || ! $this->currentVariant) {
            return [];
        }

        $allVariants = $this->product->variants;
        $currentAttrs = $this->currentVariant->variant_attributes ?? [];

        // Collect all attribute codes across approved variants
        $attrCodes = [];
        foreach ($allVariants as $variant) {
            foreach ($variant->variant_attributes ?? [] as $code => $val) {
                $attrCodes[$code] = true;
            }
        }

        $matrix = [];

        foreach (array_keys($attrCodes) as $code) {
            $options = [];

            // Get distinct values for this attribute code
            $distinctVals = [];
            foreach ($allVariants as $variant) {
                $attrs = $variant->variant_attributes ?? [];
                if (isset($attrs[$code]) && ! in_array($attrs[$code], $distinctVals, true)) {
                    $distinctVals[] = $attrs[$code];
                }
            }

            foreach ($distinctVals as $val) {
                $isSelected = isset($currentAttrs[$code]) && (string) $currentAttrs[$code] === (string) $val;

                // Candidate combo: swap this attribute to $val keeping other current selections
                $candidateCombo = array_merge($currentAttrs, [$code => $val]);

                // Find variant matching the candidate combination
                $matchedVariant = $allVariants->first(function (ProductVariant $variant) use ($candidateCombo) {
                    $vAttrs = $variant->variant_attributes ?? [];
                    foreach ($candidateCombo as $k => $v) {
                        if (! isset($vAttrs[$k]) || (string) $vAttrs[$k] !== (string) $v) {
                            return false;
                        }
                    }

                    return true;
                });

                $options[] = [
                    'value' => (string) $val,
                    'label' => (string) $val,
                    'is_selected' => $isSelected,
                    'is_available' => $matchedVariant !== null,
                    'target_upid' => $matchedVariant?->upid,
                ];
            }

            $attrName = ucfirst(str_replace(['_', '-'], ' ', $code));
            if ($this->product->vertical && $this->product->vertical->attributes) {
                $def = $this->product->vertical->attributes->firstWhere('code', $code);
                if ($def && filled($def->name)) {
                    $attrName = $def->name;
                }
            }

            $matrix[$code] = [
                'name' => $attrName,
                'options' => $options,
            ];
        }

        return $matrix;
    }

    #[Computed]
    public function reviewSummary(): ?\App\Reviews\Models\ReviewSummary
    {
        if (! config('reviews.enabled', true) || ! $this->product) {
            return null;
        }

        return app(\App\Reviews\Services\ProductReviewService::class)->getSummary($this->product->id);
    }

    public function addToCart(CartService $cartService): void
    {
        $listing = $this->bestListing;
        if (! $listing) {
            session()->flash('cart_error', 'This item is not available.');
            return;
        }

        if ($this->isOwnListing) {
            session()->flash('cart_error', 'You cannot add your own listings to your cart.');
            return;
        }

        $res = $cartService->add($listing->id, 1);
        if ($res['success']) {
            $this->dispatch('cart-updated');
            session()->flash('cart_success', 'Added to cart successfully.');
        } else {
            session()->flash('cart_error', $res['message'] ?? 'Could not add to cart.');
        }
    }

    public function buyNow(CheckoutIntentService $checkoutService): void
    {
        $listing = $this->bestListing;
        if (! $listing) {
            session()->flash('cart_error', 'This item is not available.');
            return;
        }

        if ($this->isOwnListing) {
            session()->flash('cart_error', 'You cannot purchase your own listings.');
            return;
        }

        if (! auth()->check()) {
            session([
                'pending_buy_now_listing_id' => $listing->id,
                'url.intended' => route('checkout.resume-buy-now'),
            ]);

            $this->redirect(route('login'));
            return;
        }

        try {
            $intent = $checkoutService->createOrReuseBuyNowIntent(auth()->user(), $listing->id, 1);
            $this->redirect(route('checkout.show', ['intent' => $intent->id]));
        } catch (\DomainException $e) {
            session()->flash('cart_error', $e->getMessage());
        }
    }
}; ?>

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <!-- Breadcrumb -->
    <nav class="mb-6 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('products.index') }}" class="hover:text-zinc-800 dark:hover:text-zinc-200" wire:navigate>Catalog</a>
        @if ($this->product->vertical)
            <span>/</span>
            <a href="{{ route('products.index', ['vertical' => $this->product->vertical->slug]) }}" class="hover:text-zinc-800 dark:hover:text-zinc-200" wire:navigate>
                {{ $this->product->vertical->name }}
            </a>
        @endif
        <span>/</span>
        <span class="truncate font-medium text-zinc-800 dark:text-zinc-200">{{ $this->product->title }}</span>
    </nav>

    <!-- Flash Alerts -->
    @if (session()->has('cart_error'))
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/50 dark:text-red-300">
            {{ session('cart_error') }}
        </div>
    @endif
    @if (session()->has('cart_success'))
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/50 dark:text-emerald-300">
            {{ session('cart_success') }}
        </div>
    @endif

    <div class="grid grid-cols-1 gap-10 md:grid-cols-2">
        <!-- Image Section -->
        <div>
            @php
                $variantImg = $this->currentVariant->images->first(fn ($img) => $img->status === ProductImageStatus::Live)
                    ?? $this->product->images->first(fn ($img) => $img->is_primary && $img->status === ProductImageStatus::Live)
                    ?? $this->product->images->first();
                $imgUrl = $variantImg ? $variantImg->url() : asset('storage/products/placeholder.svg');
            @endphp
            <div class="aspect-square w-full overflow-hidden rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
                <img src="{{ $imgUrl }}" alt="{{ $this->product->title }}" class="h-full w-full object-contain" />
            </div>
        </div>

        <!-- Details Section -->
        <div class="space-y-6">
            <div>
                <p class="text-xs font-bold uppercase tracking-wider text-amber-600 dark:text-amber-400 mb-1">
                    {{ $this->product->brand?->name ?? 'Brand' }}
                </p>
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-3xl">
                    {{ $this->product->title }}
                </h1>
                @if ($this->currentVariant && count($this->currentVariant->variant_attributes ?? []) > 0)
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $this->currentVariant->displayTitle() }}
                    </p>
                @endif
                <div class="mt-2">
                    <x-product-rating-pill :summary="$this->reviewSummary" />
                </div>
            </div>

            <!-- Pricing Box -->
            @php
                $listing = $this->bestListing;
                $inStock = $listing && (int) $listing->stock > 0;
                $sellingPrice = $listing ? $listing->selling_price : $this->product->min_price;
                $mrp = $listing ? $listing->mrp : $this->product->buy_box_mrp;
                $discountPercent = $mrp && $mrp > $sellingPrice ? (int) round((($mrp - $sellingPrice) / $mrp) * 100) : 0;
            @endphp

            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
                <div class="flex items-baseline gap-3">
                    <span class="text-3xl font-extrabold text-zinc-900 dark:text-white">
                        ₹{{ number_format((int) $sellingPrice / 100) }}
                    </span>
                    @if ($mrp && $mrp > $sellingPrice)
                        <span class="text-base text-zinc-400 line-through">
                            ₹{{ number_format((int) $mrp / 100) }}
                        </span>
                        <span class="inline-flex items-center rounded-md bg-emerald-600 px-2 py-0.5 text-xs font-semibold text-white">
                            {{ $discountPercent }}% OFF
                        </span>
                    @endif
                </div>

                <div class="flex items-center gap-2">
                    @if ($inStock)
                        <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                            <span class="h-2 w-2 rounded-full bg-emerald-500"></span>
                            In Stock
                        </span>
                    @else
                        <span class="inline-flex items-center gap-1.5 text-xs font-semibold text-red-600 dark:text-red-400">
                            <span class="h-2 w-2 rounded-full bg-red-500"></span>
                            Out of stock
                        </span>
                    @endif

                    @if ($listing?->sellerProfile)
                        <span class="text-xs text-zinc-500">&middot;</span>
                        <span class="text-xs text-zinc-500 dark:text-zinc-400">
                            Sold by <a href="{{ route('sellers.show', ['slug' => $listing->sellerProfile->store_slug]) }}" class="font-bold text-zinc-800 hover:underline dark:text-zinc-200">{{ $listing->sellerProfile->store_name }}</a>
                        </span>
                    @endif
                </div>

                {{-- Call to Action Buttons --}}
                <div class="pt-2 flex flex-col sm:flex-row gap-3">
                    @if ($this->isOwnListing)
                        <div class="w-full rounded-xl border border-amber-300 bg-amber-50 p-3 text-center text-xs font-medium text-amber-900 dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200">
                            You are the seller of this listing. Purchasing your own items is disabled.
                        </div>
                    @elseif (! $listing || ! $inStock)
                        <button type="button" disabled class="flex-1 rounded-xl bg-zinc-200 px-6 py-3.5 text-sm font-bold text-zinc-400 cursor-not-allowed dark:bg-zinc-800 dark:text-zinc-500 text-center">
                            {{ ! $listing ? 'Currently unavailable' : 'Out of stock' }}
                        </button>
                    @else
                        <!-- Add to Cart / Go to Cart Button -->
                        @if ($this->isInCart)
                            <a
                                href="{{ route('cart') }}"
                                class="flex-1 rounded-xl bg-amber-500 px-6 py-3.5 text-center text-sm font-bold text-white shadow-sm hover:bg-amber-600 transition flex items-center justify-center gap-2"
                                wire:navigate
                            >
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" />
                                </svg>
                                GO TO CART
                            </a>
                        @else
                            <button
                                type="button"
                                wire:click="addToCart"
                                wire:loading.attr="disabled"
                                class="flex-1 rounded-xl bg-amber-500 px-6 py-3.5 text-center text-sm font-bold text-white shadow-sm hover:bg-amber-600 active:scale-[0.99] transition flex items-center justify-center gap-2"
                            >
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.25 3h1.386c.51 0 .955.343 1.087.835l.383 1.437M7.5 14.25a3 3 0 00-3 3h15.75m-12.75-3h11.218c1.121-2.3 2.1-4.684 2.924-7.138a60.114 60.114 0 00-16.536-1.84M7.5 14.25L5.106 5.272M6 20.25a.75.75 0 11-1.5 0 .75.75 0 011.5 0zm12.75 0a.75.75 0 11-1.5 0 .75.75 0 011.5 0z" />
                                </svg>
                                ADD TO CART
                            </button>
                        @endif

                        <!-- Buy Now Button -->
                        <button
                            type="button"
                            wire:click="buyNow"
                            wire:loading.attr="disabled"
                            class="flex-1 rounded-xl bg-orange-600 px-6 py-3.5 text-center text-sm font-bold text-white shadow-sm hover:bg-orange-700 active:scale-[0.99] transition flex items-center justify-center gap-2"
                        >
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                            </svg>
                            BUY NOW
                        </button>
                    @endif
                </div>
            </div>

            <!-- Multi-Attribute Variant Selector -->
            @if (! empty($this->attributeMatrix))
                <div class="space-y-4 rounded-xl border border-zinc-200 bg-white p-5 dark:border-zinc-800 dark:bg-zinc-900">
                    @foreach ($this->attributeMatrix as $code => $group)
                        <div>
                            <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2">
                                {{ $group['name'] }}
                            </h3>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($group['options'] as $opt)
                                    @if ($opt['is_available'])
                                        <a
                                            href="{{ route('product.show', ['slug' => $this->product->slug, 'upid' => $opt['target_upid']]) }}"
                                            class="rounded-lg border px-3.5 py-2 text-xs font-semibold transition {{ $opt['is_selected'] ? 'border-amber-500 bg-amber-50 text-amber-900 ring-2 ring-amber-500/30 dark:bg-amber-950 dark:text-amber-200' : 'border-zinc-200 bg-white text-zinc-800 hover:border-zinc-300 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200' }}"
                                            wire:navigate
                                        >
                                            {{ $opt['label'] }}
                                        </a>
                                    @else
                                        <button
                                            type="button"
                                            disabled
                                            title="Unavailable in this combination"
                                            class="rounded-lg border border-dashed border-zinc-300 bg-zinc-100 px-3.5 py-2 text-xs font-medium text-zinc-400 line-through cursor-not-allowed opacity-50 dark:border-zinc-700 dark:bg-zinc-800/50 dark:text-zinc-600"
                                        >
                                            {{ $opt['label'] }}
                                        </button>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif

            <!-- Highlights -->
            @if (! empty($this->product->highlights))
                <div>
                    <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2">Highlights</h3>
                    <ul class="list-disc pl-5 text-sm text-zinc-700 dark:text-zinc-300 space-y-1">
                        @foreach ($this->product->highlights as $highlight)
                            <li>{{ $highlight }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <!-- Description -->
            <div>
                <h3 class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2">Description</h3>
                <p class="text-sm leading-relaxed text-zinc-600 dark:text-zinc-400">
                    {{ $this->product->description }}
                </p>
            </div>
        </div>
    </div>

    <!-- Reviews Section -->
    <livewire:product-reviews-section :product-id="$this->product->id" />
</div>
