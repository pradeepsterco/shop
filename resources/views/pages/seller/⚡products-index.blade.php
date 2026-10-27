<?php

use App\Enums\ListingStatus;
use App\Enums\ProductQcStatus;
use App\Models\Product;
use App\Models\SellerProfile;
use App\Models\User;
use App\Services\VariantSyncService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.seller')] #[Title('My Products')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status_filter = 'all';

    public ?int $selectedProductId = null;
    public bool $showDetailModal = false;

    public bool $showStockModal = false;
    public ?int $stockModalProductId = null;
    public array $stockData = [];
    public array $openedListingIds = [];

    #[Computed]
    public function sellerProfile(): ?SellerProfile
    {
        /** @var User $user */
        $user = Auth::user();

        return $user?->sellerProfile;
    }

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function openDetailModal(int $id): void
    {
        $this->selectedProductId = $id;
        $this->showDetailModal = true;
    }

    public function closeDetailModal(): void
    {
        $this->showDetailModal = false;
        $this->selectedProductId = null;
    }

    public function openStockModal(int $id): void
    {
        $seller = $this->sellerProfile;
        if (! $seller) {
            return;
        }

        $product = Product::with([
            'variants.listings' => fn ($q) => $q->where('seller_profile_id', $seller->id),
        ])
            ->where(function ($q) use ($seller) {
                $q->where('created_by', $seller->id)
                    ->orWhereHas('listings', fn ($l) => $l->where('seller_profile_id', $seller->id));
            })
            ->find($id);

        if (! $product) {
            return;
        }

        $this->stockModalProductId = $product->id;
        $this->stockData = [];
        $this->openedListingIds = [];
        $this->resetErrorBag();

        foreach ($product->variants as $variant) {
            $listing = $variant->listings->first();
            if ($listing) {
                $this->openedListingIds[] = $listing->id;
                $label = is_array($variant->variant_attributes) && ! empty($variant->variant_attributes)
                    ? collect($variant->variant_attributes)->map(fn ($v, $k) => ucfirst($k).': '.$v)->implode(', ')
                    : ($variant->variant_key ?: $variant->upid);

                $this->stockData[$listing->id] = [
                    'listing_id' => $listing->id,
                    'original' => (int) $listing->stock,
                    'current' => (int) $listing->stock,
                    'variant_label' => $label,
                    'sku' => $listing->sku ?: '—',
                ];
            }
        }

        $this->showStockModal = true;
    }

    public function closeStockModal(): void
    {
        $this->showStockModal = false;
        $this->stockModalProductId = null;
        $this->stockData = [];
        $this->openedListingIds = [];
        $this->resetErrorBag();
    }

    public function setStockZero(int $listingId): void
    {
        if (isset($this->stockData[$listingId])) {
            $this->stockData[$listingId]['current'] = 0;
        }
    }

    public function saveStock(): void
    {
        $seller = $this->sellerProfile;
        if (! $seller || ! $this->stockModalProductId) {
            return;
        }

        $product = Product::where(function ($q) use ($seller) {
            $q->where('created_by', $seller->id)
                ->orWhereHas('listings', fn ($l) => $l->where('seller_profile_id', $seller->id));
        })->find($this->stockModalProductId);

        if (! $product) {
            abort(403, 'Unauthorized product access.');
        }

        $this->validate([
            'stockData' => ['required', 'array'],
            'stockData.*.current' => ['required', 'integer', 'min:0', 'max:999999'],
        ], [
            'stockData.*.current.required' => 'Stock is required.',
            'stockData.*.current.integer' => 'Stock must be an integer.',
            'stockData.*.current.min' => 'Stock cannot be negative.',
            'stockData.*.current.max' => 'Stock cannot exceed 999,999.',
        ]);

        $stockChanges = [];
        foreach ($this->stockData as $listingId => $data) {
            if (! in_array($listingId, $this->openedListingIds, true)) {
                abort(403, 'Unauthorized listing offer.');
            }

            $stockChanges[$listingId] = [
                'original' => (int) $data['original'],
                'new' => (int) $data['current'],
            ];
        }

        $syncService = new VariantSyncService();
        $syncService->updateStock($product, $seller, $stockChanges);

        $title = $product->title;
        $this->closeStockModal();
        session()->flash('status', "Stock updated successfully for [{$title}].");
    }

    #[Computed]
    public function stockModalProduct(): ?Product
    {
        if (! $this->stockModalProductId) {
            return null;
        }

        $seller = $this->sellerProfile;
        if (! $seller) {
            return null;
        }

        return Product::with([
            'variants.listings' => fn ($q) => $q->where('seller_profile_id', $seller->id),
        ])
            ->where(function ($q) use ($seller) {
                $q->where('created_by', $seller->id)
                    ->orWhereHas('listings', fn ($l) => $l->where('seller_profile_id', $seller->id));
            })
            ->find($this->stockModalProductId);
    }

    public function deleteDraft(int $id): void
    {
        /** @var User $user */
        $user = Auth::user();
        $seller = $user->sellerProfile;

        if (! $seller) {
            return;
        }

        $product = Product::where('created_by', $seller->id)
            ->where('qc_status', ProductQcStatus::Draft)
            ->findOrFail($id);

        $title = $product->title;
        $product->delete();

        session()->flash('status', "Draft [{$title}] deleted successfully.");
    }

    public function getProductsProperty(): LengthAwarePaginator
    {
        $seller = $this->sellerProfile;

        if (! $seller) {
            return Product::whereRaw('1 = 0')->paginate(12);
        }

        return Product::with([
            'brand',
            'vertical.category',
            'variants.listings' => fn ($q) => $q->where('seller_profile_id', $seller->id),
            'images',
            'reviewer',
        ])
            ->where(function ($q) use ($seller) {
                $q->where('created_by', $seller->id)
                    ->orWhereHas('listings', fn ($l) => $l->where('seller_profile_id', $seller->id));
            })
            ->when($this->status_filter === 'needs_update', fn ($q) => $q->where('needs_update', true))
            ->when($this->status_filter !== 'all' && $this->status_filter !== 'needs_update', fn ($q) => $q->where('qc_status', $this->status_filter))
            ->when($this->search !== '', function ($q) {
                $term = trim($this->search);
                $q->where(function ($sub) use ($term) {
                    $sub->where('title', 'like', "%{$term}%")
                        ->orWhere('slug', 'like', "%{$term}%")
                        ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$term}%"))
                        ->orWhereHas('variants', fn ($v) => $v->where('upid', 'like', "%{$term}%")->orWhere('barcode', 'like', "%{$term}%"));
                });
            })
            ->latest('id')
            ->paginate(12);
    }

    public function getCountsProperty(): array
    {
        $seller = $this->sellerProfile;
        if (! $seller) {
            return ['all' => 0, 'qc_pending' => 0, 'approved' => 0, 'rejected' => 0, 'draft' => 0, 'needs_update' => 0];
        }

        $base = Product::where(function ($q) use ($seller) {
            $q->where('created_by', $seller->id)
                ->orWhereHas('listings', fn ($l) => $l->where('seller_profile_id', $seller->id));
        });

        return [
            'all' => (clone $base)->count(),
            'qc_pending' => (clone $base)->where('qc_status', ProductQcStatus::QcPending)->count(),
            'approved' => (clone $base)->where('qc_status', ProductQcStatus::Approved)->count(),
            'rejected' => (clone $base)->where('qc_status', ProductQcStatus::Rejected)->count(),
            'draft' => (clone $base)->where('qc_status', ProductQcStatus::Draft)->count(),
            'needs_update' => (clone $base)->where('needs_update', true)->count(),
        ];
    }

    public function getSelectedProductProperty(): ?Product
    {
        if (! $this->selectedProductId) {
            return null;
        }

        $seller = $this->sellerProfile;
        if (! $seller) {
            return null;
        }

        return Product::with([
            'brand',
            'vertical.category',
            'variants.listings' => fn ($q) => $q->where('seller_profile_id', $seller->id),
            'variants.images',
            'images',
            'reviewer',
        ])
            ->where(function ($q) use ($seller) {
                $q->where('created_by', $seller->id)
                    ->orWhereHas('listings', fn ($l) => $l->where('seller_profile_id', $seller->id));
            })
            ->find($this->selectedProductId);
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">My Products</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Track products added to your store, monitor QC approval progress, and update prices & stock.
            </p>
        </div>

        <div>
            <a href="{{ route('seller.products.create') }}" wire:navigate
               class="inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2.5 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                <span>Add New Product</span>
            </a>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300">
            {{ session('status') }}
        </div>
    @endif

    <!-- Status Tabs & Search -->
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-zinc-200 dark:border-zinc-800 pb-3">
            <nav class="flex flex-wrap gap-2 text-xs font-medium">
                <button type="button" wire:click="$set('status_filter', 'all')"
                        class="rounded-lg px-3 py-1.5 transition flex items-center gap-1.5 {{ $status_filter === 'all' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900 font-bold' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}">
                    <span>All Products</span>
                    <span class="rounded-full bg-zinc-700 text-white px-1.5 py-0.2 text-[10px] dark:bg-zinc-200 dark:text-zinc-900">{{ $this->counts['all'] }}</span>
                </button>

                <button type="button" wire:click="$set('status_filter', 'qc_pending')"
                        class="rounded-lg px-3 py-1.5 transition flex items-center gap-1.5 {{ $status_filter === 'qc_pending' ? 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200 font-bold' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}">
                    <span>Under Review</span>
                    <span class="rounded-full bg-amber-200 px-1.5 py-0.2 text-[10px] font-bold text-amber-900 dark:bg-amber-900 dark:text-amber-200">{{ $this->counts['qc_pending'] }}</span>
                </button>

                <button type="button" wire:click="$set('status_filter', 'approved')"
                        class="rounded-lg px-3 py-1.5 transition flex items-center gap-1.5 {{ $status_filter === 'approved' ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200 font-bold' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}">
                    <span>Approved & Live</span>
                    <span class="rounded-full bg-emerald-200 px-1.5 py-0.2 text-[10px] font-bold text-emerald-900 dark:bg-emerald-900 dark:text-emerald-200">{{ $this->counts['approved'] }}</span>
                </button>

                <button type="button" wire:click="$set('status_filter', 'rejected')"
                        class="rounded-lg px-3 py-1.5 transition flex items-center gap-1.5 {{ $status_filter === 'rejected' ? 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200 font-bold' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}">
                    <span>QC Rejected</span>
                    <span class="rounded-full bg-red-200 px-1.5 py-0.2 text-[10px] font-bold text-red-900 dark:bg-red-900 dark:text-red-200">{{ $this->counts['rejected'] }}</span>
                </button>

                <button type="button" wire:click="$set('status_filter', 'draft')"
                        class="rounded-lg px-3 py-1.5 transition flex items-center gap-1.5 {{ $status_filter === 'draft' ? 'bg-zinc-200 text-zinc-900 dark:bg-zinc-800 dark:text-white font-bold' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}">
                    <span>Drafts</span>
                    <span class="rounded-full bg-zinc-300 px-1.5 py-0.2 text-[10px] font-bold text-zinc-800 dark:bg-zinc-700 dark:text-zinc-300">{{ $this->counts['draft'] }}</span>
                </button>

                <button type="button" wire:click="$set('status_filter', 'needs_update')"
                        class="rounded-lg px-3 py-1.5 transition flex items-center gap-1.5 {{ $status_filter === 'needs_update' ? 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200 font-bold' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}">
                    <span>Needs Update</span>
                    <span class="rounded-full bg-amber-200 px-1.5 py-0.2 text-[10px] font-bold text-amber-900 dark:bg-amber-900 dark:text-amber-200">{{ $this->counts['needs_update'] }}</span>
                </button>
            </nav>

            <div class="relative w-full sm:w-64">
                <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search product, brand, UPID..."
                       class="w-full rounded-lg border border-zinc-300 bg-white py-1.5 pl-8 pr-3 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                <svg class="pointer-events-none absolute left-2.5 top-2 size-3.5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                </svg>
            </div>
        </div>
    </div>

    <!-- Product Cards Grid / List -->
    <div class="grid grid-cols-1 gap-4">
        @forelse ($this->products as $prod)
            @php
                $primaryImg = $prod->images->firstWhere('is_primary', true) ?? $prod->images->first();
                $listings = $prod->variants->flatMap->listings;
                $totalStock = $listings->sum('stock');
                $minPrice = $listings->min('selling_price');
                $maxPrice = $listings->max('selling_price');
                $minMrp = $listings->min('mrp');
                $isCreator = $prod->created_by === $this->sellerProfile?->id;
            @endphp
            <div wire:key="prod-card-{{ $prod->id }}"
                 class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-5 shadow-xs transition hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700">
                <div class="flex flex-col md:flex-row md:items-center justify-between gap-4">
                    <!-- Left: Image & Title info -->
                    <div class="flex items-start gap-4 min-w-0">
                        @if ($primaryImg && Storage::disk('public')->exists($primaryImg->path))
                            <img src="{{ Storage::disk('public')->url($primaryImg->path) }}" alt="{{ $prod->title }}"
                                 class="size-20 shrink-0 rounded-xl object-cover border border-zinc-200 dark:border-zinc-700" />
                        @else
                            <div class="flex size-20 shrink-0 items-center justify-center rounded-xl bg-zinc-100 text-zinc-400 border border-zinc-200 dark:bg-zinc-800 dark:border-zinc-700">
                                <svg class="size-8" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                </svg>
                            </div>
                        @endif

                        <div class="min-w-0 flex-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="rounded bg-zinc-100 px-2 py-0.5 text-[11px] font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                    {{ $prod->brand?->name ?? 'Generic' }}
                                </span>
                                <span class="text-xs text-zinc-400">&bull;</span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ $prod->vertical?->name }}</span>
                                @if (! $isCreator)
                                    <span class="rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-semibold text-blue-700 dark:bg-blue-950 dark:text-blue-300">
                                        Catalog Listing
                                    </span>
                                @endif
                            </div>

                            <h3 class="mt-1 text-base font-bold text-zinc-900 dark:text-white line-clamp-1">
                                {{ $prod->title }}
                            </h3>

                            <div class="mt-2 flex flex-wrap items-center gap-4 text-xs text-zinc-500 dark:text-zinc-400">
                                <div>
                                    <span>Variants:</span>
                                    <strong class="text-zinc-800 dark:text-zinc-200">{{ $prod->variants->count() }}</strong>
                                </div>

                                <div>
                                    <span>Stock:</span>
                                    <button type="button" wire:click="openStockModal({{ $prod->id }})"
                                            class="inline-flex items-center gap-1 font-bold underline decoration-dotted hover:decoration-solid cursor-pointer {{ $totalStock > 0 ? 'text-zinc-800 dark:text-zinc-200' : 'text-red-500' }}"
                                            title="Click to update stock">
                                        <span>{{ $totalStock }} units</span>
                                        <svg class="size-3 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.232 5.232l3.536 3.536m-2.036-5.036a2.5 2.5 0 113.536 3.536L6.5 21.036H3v-3.572L16.732 3.732z" />
                                        </svg>
                                    </button>
                                </div>

                                @if ($minPrice !== null)
                                    <div class="font-mono text-zinc-900 dark:text-white font-bold">
                                        @if ($minPrice === $maxPrice)
                                            ₹{{ number_format($minPrice / 100, 2) }}
                                        @else
                                            ₹{{ number_format($minPrice / 100, 2) }} – ₹{{ number_format($maxPrice / 100, 2) }}
                                        @endif
                                        @if ($minMrp && $minMrp > $minPrice)
                                            <span class="text-xs font-normal text-zinc-400 line-through ml-1">₹{{ number_format($minMrp / 100, 2) }}</span>
                                        @endif
                                    </div>
                                @endif
                            </div>

                            @if ($prod->qc_status === ProductQcStatus::Rejected && $prod->qc_notes)
                                <div class="mt-2 rounded-lg border border-red-200 bg-red-50 p-2 text-xs text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300 flex items-start gap-2">
                                    <svg class="size-4 shrink-0 text-red-500 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <div>
                                        <strong>QC Feedback:</strong> {{ $prod->qc_notes }}
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    <!-- Right: Status Badge & Action buttons -->
                    <div class="flex flex-row md:flex-col items-center md:items-end justify-between md:justify-center gap-3 shrink-0 pt-3 md:pt-0 border-t md:border-t-0 border-zinc-100 dark:border-zinc-800">
                        <div class="flex flex-wrap items-center gap-1.5">
                            @if ($prod->needs_update)
                                <span class="inline-flex items-center gap-1 rounded-full bg-amber-100 px-2.5 py-1 text-xs font-bold text-amber-800 dark:bg-amber-950 dark:text-amber-200 border border-amber-300 dark:border-amber-800">
                                    ⚠️ Needs Update
                                </span>
                            @endif

                            @if ($prod->qc_status === ProductQcStatus::QcPending)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-950/50 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                                    <svg class="size-3.5 animate-spin text-amber-600" fill="none" viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                    </svg>
                                    Under QC Review
                                </span>
                            @elseif ($prod->qc_status === ProductQcStatus::Approved)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                                    <span class="size-1.5 rounded-full bg-emerald-500"></span>
                                    Approved & Live
                                </span>
                            @elseif ($prod->qc_status === ProductQcStatus::Rejected)
                                <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-3 py-1 text-xs font-semibold text-red-700 dark:bg-red-950/50 dark:text-red-300 border border-red-200 dark:border-red-800">
                                    <span class="size-1.5 rounded-full bg-red-500"></span>
                                    QC Rejected
                                </span>
                            @else
                                <span class="inline-flex items-center rounded-full bg-zinc-100 px-3 py-1 text-xs font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                    Draft
                                </span>
                            @endif
                        </div>

                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="openStockModal({{ $prod->id }})"
                                    class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 dark:hover:text-white transition cursor-pointer">
                                Stock
                            </button>

                            <button type="button" wire:click="openDetailModal({{ $prod->id }})"
                                    class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 dark:hover:text-white transition cursor-pointer">
                                Details
                            </button>

                            @if ($prod->needs_update)
                                <a href="{{ route('seller.products.edit', $prod) }}" wire:navigate
                                   class="rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700 transition shadow-2xs">
                                    Fix Issues
                                </a>
                            @elseif ($prod->qc_status === ProductQcStatus::Approved || $prod->qc_status === ProductQcStatus::Rejected)
                                <a href="{{ route('seller.products.edit', $prod) }}" wire:navigate
                                   class="rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-2xs">
                                    {{ $prod->qc_status === ProductQcStatus::Rejected ? 'Fix & Resubmit' : 'Manage' }}
                                </a>
                            @elseif ($prod->qc_status === ProductQcStatus::Draft)
                                <button type="button" wire:click="deleteDraft({{ $prod->id }})" wire:confirm="Are you sure you want to discard this draft?"
                                        class="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100 dark:border-red-900/50 dark:bg-red-950 dark:text-red-300 transition">
                                    Delete
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        @empty
            <div class="rounded-2xl border border-zinc-200 bg-white p-12 text-center shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-500">
                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20 7l-8-4-8 4m16 0l-8 4m8-4v10l-8 4m0-10L4 7m8 4v10M4 7v10l8 4"/>
                    </svg>
                </div>
                <h3 class="mt-4 text-base font-bold text-zinc-900 dark:text-white">No products found</h3>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 max-w-sm mx-auto">
                    @if ($status_filter !== 'all')
                        No products currently in <strong>{{ ucfirst(str_replace('_', ' ', $status_filter)) }}</strong> status.
                    @else
                        You have not created or listed any products yet.
                    @endif
                </p>
                <div class="mt-6">
                    <a href="{{ route('seller.products.create') }}" wire:navigate
                       class="inline-flex items-center gap-2 rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700 transition shadow-xs">
                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                        </svg>
                        <span>Add Your First Product</span>
                    </a>
                </div>
            </div>
        @endforelse
    </div>

    @if ($this->products->hasPages())
        <div class="border-t border-zinc-200 pt-4 dark:border-zinc-800">
            {{ $this->products->links() }}
        </div>
    @endif

    <!-- PRODUCT DETAIL & REJECTION FEEDBACK MODAL -->
    @if ($showDetailModal && $this->selectedProduct)
        @php
            $prod = $this->selectedProduct;
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="closeDetailModal" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-3xl max-h-[90vh] overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-6 shadow-2xl dark:border-zinc-800 dark:bg-zinc-900 space-y-5">
                <div class="flex items-start justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-bold text-zinc-900 dark:text-white">{{ $prod->title }}</h3>
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $prod->qc_status === ProductQcStatus::Approved ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : ($prod->qc_status === ProductQcStatus::Rejected ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300') }}">
                                {{ $prod->qc_status->label() }}
                            </span>
                        </div>
                        <p class="text-xs text-zinc-400 mt-0.5">Brand: {{ $prod->brand?->name }} &bull; Vertical: {{ $prod->vertical?->name }}</p>
                    </div>
                    <button type="button" wire:click="closeDetailModal" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                @if ($prod->qc_status === ProductQcStatus::Rejected && $prod->qc_notes)
                    <div class="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900/50 dark:bg-red-950/30 text-xs space-y-1">
                        <h4 class="font-bold text-red-800 dark:text-red-300 flex items-center gap-1.5">
                            <svg class="size-4 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                            </svg>
                            Rejection Feedback from Marketplace QC Team
                        </h4>
                        <p class="text-red-700 dark:text-red-300 font-medium whitespace-pre-line">{{ $prod->qc_notes }}</p>
                        <p class="text-[11px] text-zinc-500 pt-1">
                            Click "Fix & Resubmit" below to update your product details according to the feedback and submit again.
                        </p>
                    </div>
                @endif

                <!-- Images preview -->
                @if ($prod->images->isNotEmpty())
                    <div>
                        <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500 mb-2">Product Images</h4>
                        <div class="flex flex-wrap gap-2">
                            @foreach ($prod->images as $img)
                                <div class="relative size-16 rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700 bg-zinc-50 dark:bg-zinc-800">
                                    @if (Storage::disk('public')->exists($img->path))
                                        <img src="{{ Storage::disk('public')->url($img->path) }}" alt="Preview" class="size-full object-cover" />
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <!-- Variants Table -->
                <div>
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500 mb-2">Your Listings & Variants ({{ $prod->variants->count() }})</h4>
                    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-800">
                        <table class="w-full text-left text-xs">
                            <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-800/50 text-zinc-500 uppercase font-semibold">
                                <tr>
                                    <th class="px-3 py-2">UPID</th>
                                    <th class="px-3 py-2">Variant</th>
                                    <th class="px-3 py-2">Your SKU</th>
                                    <th class="px-3 py-2 text-right">MRP</th>
                                    <th class="px-3 py-2 text-right">Selling Price</th>
                                    <th class="px-3 py-2 text-center">Stock</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach ($prod->variants as $var)
                                    @php
                                        $listing = $var->listings->first();
                                    @endphp
                                    <tr>
                                        <td class="px-3 py-2 font-mono font-bold text-zinc-900 dark:text-white">{{ $var->upid }}</td>
                                        <td class="px-3 py-2">
                                            @if (is_array($var->variant_attributes))
                                                @foreach ($var->variant_attributes as $attrKey => $attrVal)
                                                    <span class="inline-block rounded bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 text-[10px] mr-1">
                                                        {{ ucfirst($attrKey) }}: {{ $attrVal }}
                                                    </span>
                                                @endforeach
                                            @else
                                                {{ $var->variant_key }}
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 font-mono text-zinc-700 dark:text-zinc-300">{{ $listing?->sku ?? '—' }}</td>
                                        <td class="px-3 py-2 text-right font-mono text-zinc-400 line-through">
                                            {{ $listing ? '₹'.number_format($listing->mrpInRupees(), 2) : '—' }}
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono font-bold text-emerald-600 dark:text-emerald-400">
                                            {{ $listing ? '₹'.number_format($listing->sellingPriceInRupees(), 2) : '—' }}
                                        </td>
                                        <td class="px-3 py-2 text-center font-bold {{ ($listing?->stock ?? 0) > 0 ? 'text-zinc-900 dark:text-white' : 'text-red-500' }}">
                                            {{ $listing?->stock ?? 0 }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="flex items-center justify-between pt-3 border-t border-zinc-100 dark:border-zinc-800">
                    <button type="button" wire:click="closeDetailModal" class="rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                        Close
                    </button>

                    @if ($prod->qc_status === ProductQcStatus::Approved || $prod->qc_status === ProductQcStatus::Rejected)
                        <a href="{{ route('seller.products.edit', $prod) }}" wire:navigate
                           class="rounded-lg bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 transition">
                            {{ $prod->qc_status === ProductQcStatus::Rejected ? 'Fix & Resubmit Product' : 'Edit Product Details' }}
                        </a>
                    @endif
                </div>
            </div>
        </div>
    @endif

    <!-- QUICK STOCK UPDATE MODAL -->
    @if ($showStockModal && $this->stockModalProduct)
        @php
            $prod = $this->stockModalProduct;
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="closeStockModal" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-xl max-h-[90vh] overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-6 shadow-2xl dark:border-zinc-800 dark:bg-zinc-900 space-y-5">
                <div class="flex items-start justify-between pb-3 border-b border-zinc-100 dark:border-zinc-800">
                    <div class="min-w-0 pr-4">
                        <div class="flex items-center gap-2">
                            <span class="rounded bg-zinc-100 px-2 py-0.5 text-[11px] font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                Quick Stock Update
                            </span>
                        </div>
                        <h3 class="mt-1 text-base font-bold text-zinc-900 dark:text-white truncate">{{ $prod->title }}</h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">
                            Update inventory levels directly. Changes apply immediately.
                        </p>
                    </div>
                    <button type="button" wire:click="closeStockModal" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200 cursor-pointer">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form wire:submit="saveStock" class="space-y-4">
                    <div class="divide-y divide-zinc-100 dark:divide-zinc-800 rounded-xl border border-zinc-200 dark:border-zinc-800 overflow-hidden bg-zinc-50/50 dark:bg-zinc-800/20">
                        @forelse ($stockData as $lId => $item)
                            <div class="p-3.5 flex flex-col sm:flex-row sm:items-center justify-between gap-3">
                                <div class="min-w-0 flex-1">
                                    <div class="font-medium text-xs text-zinc-900 dark:text-white truncate">
                                        {{ $item['variant_label'] ?? 'Variant' }}
                                    </div>
                                    <div class="flex items-center gap-2 mt-0.5 text-[11px] text-zinc-500 dark:text-zinc-400">
                                        <span>SKU: <code class="font-mono">{{ $item['sku'] ?? '—' }}</code></span>
                                        <span>&bull;</span>
                                        <span>Current: <strong class="text-zinc-700 dark:text-zinc-300">{{ $item['original'] ?? 0 }}</strong></span>
                                    </div>
                                    @error("stockData.{$lId}.current")
                                        <p class="text-[11px] text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                                    @enderror
                                </div>

                                <div class="flex items-center gap-2 shrink-0">
                                    <input
                                        type="number"
                                        min="0"
                                        max="999999"
                                        step="1"
                                        wire:model="stockData.{{ $lId }}.current"
                                        class="w-24 rounded-lg border border-zinc-300 bg-white px-2.5 py-1.5 text-center text-xs font-mono font-bold text-zinc-900 shadow-2xs focus:border-zinc-950 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:focus:border-white"
                                    />
                                    <button
                                        type="button"
                                        wire:click="setStockZero({{ $lId }})"
                                        class="rounded-lg border border-zinc-200 bg-zinc-100 px-2.5 py-1.5 text-[11px] font-semibold text-zinc-600 hover:bg-zinc-200 hover:text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-700 dark:hover:text-white transition cursor-pointer"
                                        title="Set stock to 0"
                                    >
                                        Set to 0
                                    </button>
                                </div>
                            </div>
                        @empty
                            <div class="p-6 text-center text-xs text-zinc-500 dark:text-zinc-400">
                                No active listings found for this product.
                            </div>
                        @endforelse
                    </div>

                    <div class="flex items-center justify-between pt-3 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="button" wire:click="closeStockModal" class="btn-secondary">
                            Cancel
                        </button>

                        <button type="submit" class="btn-primary" wire:loading.attr="disabled">
                            <span wire:loading.remove wire:target="saveStock">Save Stock</span>
                            <span wire:loading wire:target="saveStock" class="inline-flex items-center gap-1.5">
                                <svg class="size-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8H4z"></path>
                                </svg>
                                <span>Saving...</span>
                            </span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
