<?php

use App\Enums\ListingStatus;
use App\Enums\ProductQcStatus;
use App\Enums\VariantQcStatus;
use App\Models\Admin;
use App\Models\Product;
use App\Models\Vertical;
use App\Services\AdminActivityLogger;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Product Catalog & QC Review')] class extends Component {
    use WithPagination;

    public string $search = '';
    public string $status_filter = 'qc_pending';
    public string $vertical_filter = '';

    // Modals
    public bool $showReviewModal = false;
    public bool $showRejectModal = false;
    public ?int $selectedProductId = null;
    public string $review_notes = '';
    public string $rejection_reason = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatusFilter(): void
    {
        $this->resetPage();
    }

    public function updatedVerticalFilter(): void
    {
        $this->resetPage();
    }

    public function openReviewModal(int $id): void
    {
        $this->authorize('catalog.qc');

        $this->selectedProductId = $id;
        $this->review_notes = '';
        $this->showReviewModal = true;
    }

    public function closeReviewModal(): void
    {
        $this->showReviewModal = false;
        $this->selectedProductId = null;
        $this->review_notes = '';
    }

    public function openRejectModal(int $id): void
    {
        $this->authorize('catalog.qc');

        $this->selectedProductId = $id;
        $this->rejection_reason = '';
        $this->showRejectModal = true;
    }

    public function closeRejectModal(): void
    {
        $this->showRejectModal = false;
        $this->selectedProductId = null;
        $this->rejection_reason = '';
    }

    public function approveProduct(int $id): void
    {
        $this->authorize('catalog.qc');

        /** @var Admin $admin */
        $admin = Auth::user();

        $product = Product::with(['variants.listings', 'images', 'brand', 'vertical.attributes'])->findOrFail($id);

        $issues = $product->variantIssues();
        if (! empty($issues) || $product->needs_update) {
            session()->flash('review_error', 'Cannot approve: product has variant integrity issues or is flagged as needing update.');

            return;
        }

        $product->approve($admin, $this->review_notes ?: null);

        // Approve all variants and activate listings
        foreach ($product->variants as $variant) {
            $variant->approve($this->review_notes ?: null);
            foreach ($variant->listings as $listing) {
                if ($listing->status === ListingStatus::Draft) {
                    $listing->activate();
                }
            }
        }

        $this->closeReviewModal();

        AdminActivityLogger::log('product.qc_approved', $product, [
            'product_id' => $product->id,
            'title' => $product->title,
            'notes' => $this->review_notes ?: null,
        ]);

        session()->flash('status', "Product [{$product->title}] and its variants have been successfully approved.");
    }

    public function confirmReject(): void
    {
        $this->authorize('catalog.qc');

        $this->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        if (! $this->selectedProductId) {
            return;
        }

        /** @var Admin $admin */
        $admin = Auth::user();

        $product = Product::with(['variants.listings', 'images'])->findOrFail($this->selectedProductId);

        $product->reject($admin, $this->rejection_reason);

        // Reject variants
        foreach ($product->variants as $variant) {
            $variant->reject($this->rejection_reason);
        }

        AdminActivityLogger::log('product.qc_rejected', $product, [
            'product_id' => $product->id,
            'title' => $product->title,
            'reason' => $this->rejection_reason,
        ]);

        $this->closeRejectModal();
        $this->closeReviewModal();

        session()->flash('status', "Product [{$product->title}] has been rejected.");
    }

    public function getProductsProperty(): LengthAwarePaginator
    {
        return Product::with([
            'brand',
            'vertical.category',
            'creator.user',
            'variants.listings',
            'images',
            'reviewer',
        ])
            ->when($this->status_filter !== 'all', fn ($q) => $q->where('qc_status', $this->status_filter))
            ->when($this->vertical_filter !== '', fn ($q) => $q->where('vertical_id', (int) $this->vertical_filter))
            ->when($this->search !== '', function ($q) {
                $term = trim($this->search);
                $q->where(function ($sub) use ($term) {
                    $sub->where('title', 'like', "%{$term}%")
                        ->orWhere('slug', 'like', "%{$term}%")
                        ->orWhereHas('brand', fn ($b) => $b->where('name', 'like', "%{$term}%"))
                        ->orWhereHas('creator', fn ($c) => $c->where('store_name', 'like', "%{$term}%"))
                        ->orWhereHas('variants', fn ($v) => $v->where('upid', 'like', "%{$term}%")->orWhere('barcode', 'like', "%{$term}%"));
                });
            })
            ->latest('id')
            ->paginate(15);
    }

    public function getPendingCountProperty(): int
    {
        return Product::where('qc_status', ProductQcStatus::QcPending)->count();
    }

    public function getApprovedCountProperty(): int
    {
        return Product::where('qc_status', ProductQcStatus::Approved)->count();
    }

    public function getRejectedCountProperty(): int
    {
        return Product::where('qc_status', ProductQcStatus::Rejected)->count();
    }

    public function getDraftCountProperty(): int
    {
        return Product::where('qc_status', ProductQcStatus::Draft)->count();
    }

    public function getVerticalsProperty()
    {
        return Vertical::orderBy('name')->get();
    }

    public function getSelectedProductProperty(): ?Product
    {
        if (! $this->selectedProductId) {
            return null;
        }

        return Product::with([
            'brand',
            'vertical.category',
            'creator.user',
            'variants.listings',
            'variants.images',
            'images',
            'reviewer',
        ])->find($this->selectedProductId);
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Product Catalog & QC Review</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Review and approve newly added products submitted by sellers. Inspect variants, specifications, and images.
            </p>
        </div>

        @if ($this->pendingCount > 0)
            <div class="inline-flex items-center gap-2 rounded-xl border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-semibold text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-300">
                <span class="size-2 rounded-full bg-amber-500 animate-pulse"></span>
                <span>{{ $this->pendingCount }} {{ Str::plural('product', $this->pendingCount) }} awaiting QC review</span>
            </div>
        @endif
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/30 dark:text-emerald-300">
            {{ session('status') }}
        </div>
    @endif

    <!-- Status Tabs & Filter Bar -->
    <div class="space-y-4">
        <div class="flex flex-wrap items-center justify-between gap-4 border-b border-zinc-200 dark:border-zinc-800 pb-3">
            <nav class="flex flex-wrap gap-2 text-xs font-medium">
                <button type="button" wire:click="$set('status_filter', 'qc_pending')"
                        class="rounded-lg px-3 py-1.5 transition flex items-center gap-1.5 {{ $status_filter === 'qc_pending' ? 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200 font-bold' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}">
                    <span>Pending QC</span>
                    <span class="rounded-full bg-amber-200 px-1.5 py-0.2 text-[10px] font-bold text-amber-900 dark:bg-amber-900 dark:text-amber-200">{{ $this->pendingCount }}</span>
                </button>

                <button type="button" wire:click="$set('status_filter', 'approved')"
                        class="rounded-lg px-3 py-1.5 transition flex items-center gap-1.5 {{ $status_filter === 'approved' ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-950 dark:text-emerald-200 font-bold' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}">
                    <span>Approved</span>
                    <span class="rounded-full bg-emerald-200 px-1.5 py-0.2 text-[10px] font-bold text-emerald-900 dark:bg-emerald-900 dark:text-emerald-200">{{ $this->approvedCount }}</span>
                </button>

                <button type="button" wire:click="$set('status_filter', 'rejected')"
                        class="rounded-lg px-3 py-1.5 transition flex items-center gap-1.5 {{ $status_filter === 'rejected' ? 'bg-red-100 text-red-900 dark:bg-red-950 dark:text-red-200 font-bold' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}">
                    <span>Rejected</span>
                    <span class="rounded-full bg-red-200 px-1.5 py-0.2 text-[10px] font-bold text-red-900 dark:bg-red-900 dark:text-red-200">{{ $this->rejectedCount }}</span>
                </button>

                <button type="button" wire:click="$set('status_filter', 'draft')"
                        class="rounded-lg px-3 py-1.5 transition flex items-center gap-1.5 {{ $status_filter === 'draft' ? 'bg-zinc-200 text-zinc-900 dark:bg-zinc-800 dark:text-white font-bold' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}">
                    <span>Drafts</span>
                    <span class="rounded-full bg-zinc-300 px-1.5 py-0.2 text-[10px] font-bold text-zinc-800 dark:bg-zinc-700 dark:text-zinc-300">{{ $this->draftCount }}</span>
                </button>

                <button type="button" wire:click="$set('status_filter', 'all')"
                        class="rounded-lg px-3 py-1.5 transition {{ $status_filter === 'all' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900 font-bold' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}">
                    <span>All Products</span>
                </button>
            </nav>

            <div class="flex flex-wrap items-center gap-3">
                <select wire:model.live="vertical_filter"
                        class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                    <option value="">All Verticals</option>
                    @foreach ($this->verticals as $vert)
                        <option value="{{ $vert->id }}">{{ $vert->name }}</option>
                    @endforeach
                </select>

                <div class="relative w-64">
                    <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search title, brand, seller, UPID..."
                           class="w-full rounded-lg border border-zinc-300 bg-white py-1.5 pl-8 pr-3 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                    <svg class="pointer-events-none absolute left-2.5 top-2 size-3.5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </div>
            </div>
        </div>
    </div>

    <!-- Products Table -->
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400">
                    <tr>
                        <th class="px-6 py-3.5">Product</th>
                        <th class="px-6 py-3.5">Vertical & Brand</th>
                        <th class="px-6 py-3.5">Seller</th>
                        <th class="px-6 py-3.5 text-center">Variants & Stock</th>
                        <th class="px-6 py-3.5 text-center">QC Status</th>
                        <th class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($this->products as $prod)
                        @php
                            $primaryImg = $prod->images->firstWhere('is_primary', true) ?? $prod->images->first();
                            $totalStock = $prod->variants->flatMap->listings->sum('stock');
                            $minPrice = $prod->variants->flatMap->listings->min('selling_price');
                            $maxPrice = $prod->variants->flatMap->listings->max('selling_price');
                        @endphp
                        <tr wire:key="prod-{{ $prod->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition">
                            <!-- Product column -->
                            <td class="px-6 py-4">
                                <div class="flex items-start gap-3">
                                    @if ($primaryImg && Storage::disk('public')->exists($primaryImg->path))
                                        <img src="{{ Storage::disk('public')->url($primaryImg->path) }}" alt="{{ $prod->title }}" class="size-12 shrink-0 rounded-lg object-cover border border-zinc-200 dark:border-zinc-700" />
                                    @else
                                        <div class="flex size-12 shrink-0 items-center justify-center rounded-lg bg-zinc-100 text-zinc-400 border border-zinc-200 dark:bg-zinc-800 dark:border-zinc-700">
                                            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                        </div>
                                    @endif
                                    <div class="min-w-0">
                                        <div class="font-semibold text-zinc-900 dark:text-white line-clamp-1" title="{{ $prod->title }}">
                                            {{ $prod->title }}
                                        </div>
                                        <div class="font-mono text-xs text-zinc-400 truncate max-w-xs">
                                            /{{ $prod->slug }}
                                        </div>
                                        <div class="mt-1 text-xs text-zinc-500">
                                            Added @date($prod->created_at, 'M j, Y')
                                        </div>
                                    </div>
                                </div>
                            </td>

                            <!-- Vertical & Brand -->
                            <td class="px-6 py-4 text-xs">
                                <div class="font-medium text-zinc-900 dark:text-white">
                                    {{ $prod->vertical?->name ?? 'None' }}
                                </div>
                                <div class="text-zinc-500 dark:text-zinc-400">
                                    {{ $prod->vertical?->category?->name ?? '—' }}
                                </div>
                                <div class="mt-1">
                                    <span class="inline-flex items-center rounded-md bg-zinc-100 px-2 py-0.5 text-[10px] font-semibold text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">
                                        {{ $prod->brand?->name ?? 'No Brand' }}
                                    </span>
                                </div>
                            </td>

                            <!-- Seller -->
                            <td class="px-6 py-4 text-xs">
                                @if ($prod->creator)
                                    <a href="{{ route('admin.sellers.show', $prod->creator) }}" wire:navigate class="font-medium text-emerald-600 hover:underline dark:text-emerald-400">
                                        {{ $prod->creator->store_name }}
                                    </a>
                                    <div class="text-zinc-400">{{ $prod->creator->user?->name }}</div>
                                    <div class="text-[11px] text-zinc-400">{{ $prod->creator->support_email }}</div>
                                @else
                                    <span class="text-zinc-400 italic">Platform / Admin</span>
                                @endif
                            </td>

                            <!-- Variants & Stock -->
                            <td class="px-6 py-4 text-center text-xs">
                                <div class="font-bold text-zinc-900 dark:text-white">
                                    {{ $prod->variants->count() }} {{ Str::plural('Variant', $prod->variants->count()) }}
                                </div>
                                @if ($minPrice !== null && $maxPrice !== null)
                                    <div class="text-zinc-600 dark:text-zinc-300 font-mono text-[11px]">
                                        @if ($minPrice === $maxPrice)
                                            ₹{{ number_format($minPrice / 100, 2) }}
                                        @else
                                            ₹{{ number_format($minPrice / 100, 2) }} - ₹{{ number_format($maxPrice / 100, 2) }}
                                        @endif
                                    </div>
                                @endif
                                <div class="mt-0.5 text-[11px] text-zinc-400">
                                    Stock: <strong class="{{ $totalStock > 0 ? 'text-zinc-800 dark:text-zinc-200' : 'text-red-500' }}">{{ $totalStock }}</strong>
                                </div>
                            </td>

                            <!-- QC Status -->
                            <td class="px-6 py-4 text-center">
                                @if ($prod->qc_status === ProductQcStatus::QcPending)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-700 dark:bg-amber-950/50 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                                        <span class="size-1.5 rounded-full bg-amber-500"></span>
                                        Pending Review
                                    </span>
                                @elseif ($prod->qc_status === ProductQcStatus::Approved)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                                        <span class="size-1.5 rounded-full bg-emerald-500"></span>
                                        Approved
                                    </span>
                                @elseif ($prod->qc_status === ProductQcStatus::Rejected)
                                    <span class="inline-flex items-center gap-1.5 rounded-full bg-red-50 px-2.5 py-1 text-xs font-semibold text-red-700 dark:bg-red-950/50 dark:text-red-300 border border-red-200 dark:border-red-800" title="{{ $prod->qc_notes }}">
                                        <span class="size-1.5 rounded-full bg-red-500"></span>
                                        Rejected
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-zinc-100 px-2.5 py-1 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                        Draft
                                    </span>
                                @endif
                            </td>

                            <!-- Actions -->
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button type="button" wire:click="openReviewModal({{ $prod->id }})"
                                            class="rounded-lg border border-zinc-200 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 transition shadow-2xs">
                                        Inspect & Review
                                    </button>

                                    @if ($prod->qc_status === ProductQcStatus::QcPending || $prod->qc_status === ProductQcStatus::Rejected)
                                        @if ($prod->needs_update || ! empty($prod->variantIssues()))
                                            <span class="inline-flex items-center rounded-lg bg-amber-100 px-2.5 py-1 text-[11px] font-bold text-amber-800 dark:bg-amber-950 dark:text-amber-200 border border-amber-300 dark:border-amber-800">
                                                Issues Pending
                                            </span>
                                        @else
                                            <button type="button" wire:click="approveProduct({{ $prod->id }})" wire:confirm="Are you sure you want to approve [{{ $prod->title }}] and all its variants?"
                                                    class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 transition shadow-2xs">
                                                Approve
                                            </button>
                                        @endif
                                    @endif

                                    @if ($prod->qc_status === ProductQcStatus::QcPending)
                                        <button type="button" wire:click="openRejectModal({{ $prod->id }})"
                                                class="rounded-lg border border-red-200 bg-red-50 px-2.5 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100 dark:border-red-900/50 dark:bg-red-950/50 dark:text-red-300 transition">
                                            Reject
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-sm text-zinc-500">
                                No products found matching the criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->products->hasPages())
            <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                {{ $this->products->links() }}
            </div>
        @endif
    </div>

    <!-- PRODUCT REVIEW MODAL -->
    @if ($showReviewModal && $this->selectedProduct)
        @php
            $prod = $this->selectedProduct;
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="closeReviewModal" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-4xl max-h-[90vh] overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-6 shadow-2xl dark:border-zinc-800 dark:bg-zinc-900 space-y-6">
                <!-- Modal Header -->
                <div class="flex items-start justify-between pb-4 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <div class="flex items-center gap-2">
                            <h3 class="text-lg font-bold text-zinc-900 dark:text-white">
                                {{ $prod->title }}
                            </h3>
                            <span class="rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $prod->qc_status === ProductQcStatus::Approved ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-300' : ($prod->qc_status === ProductQcStatus::Rejected ? 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-950 dark:text-amber-300') }}">
                                {{ $prod->qc_status->label() }}
                            </span>
                        </div>
                        <p class="text-xs text-zinc-500 font-mono mt-0.5">Slug: /{{ $prod->slug }} &bull; Brand: {{ $prod->brand?->name }} &bull; Vertical: {{ $prod->vertical?->name }}</p>
                    </div>
                    <button type="button" wire:click="closeReviewModal" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <!-- Seller Info Callout -->
                <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-800/40 text-xs">
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <span class="text-zinc-400 block">Submitted By Store:</span>
                            <strong class="text-zinc-900 dark:text-white text-sm">{{ $prod->creator?->store_name ?? 'System' }}</strong>
                        </div>
                        <div>
                            <span class="text-zinc-400 block">Seller Contact:</span>
                            <span class="text-zinc-700 dark:text-zinc-300">{{ $prod->creator?->support_email }} &bull; {{ $prod->creator?->support_phone }}</span>
                        </div>
                        <div>
                            <span class="text-zinc-400 block">Submission Date:</span>
                            <span class="text-zinc-700 dark:text-zinc-300">@datetime($prod->created_at)</span>
                        </div>
                    </div>
                    @if ($prod->qc_notes)
                        <div class="mt-3 pt-3 border-t border-zinc-200 dark:border-zinc-700">
                            <span class="text-zinc-400 block">QC Feedback / Reason:</span>
                            <p class="text-amber-700 dark:text-amber-300 font-medium">{{ $prod->qc_notes }}</p>
                            @if ($prod->reviewer)
                                <span class="text-[10px] text-zinc-400">Reviewed by {{ $prod->reviewer->name }} on @datetime($prod->reviewed_at)</span>
                            @endif
                        </div>
                    @endif
                </div>

                <!-- Product Images Gallery -->
                <div>
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500 mb-2">Uploaded Images ({{ $prod->images->count() }})</h4>
                    <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-6 gap-3">
                        @forelse ($prod->images as $img)
                            <div class="relative group rounded-lg overflow-hidden border border-zinc-200 dark:border-zinc-700 aspect-square bg-zinc-50 dark:bg-zinc-800">
                                @if (Storage::disk('public')->exists($img->path))
                                    <img src="{{ Storage::disk('public')->url($img->path) }}" alt="Preview" class="size-full object-cover" />
                                @endif
                                @if ($img->is_primary)
                                    <span class="absolute top-1 left-1 rounded bg-emerald-600 px-1.5 py-0.5 text-[9px] font-bold text-white shadow-xs">
                                        Primary
                                    </span>
                                @endif
                            </div>
                        @empty
                            <p class="text-xs text-zinc-400 italic">No images uploaded.</p>
                        @endforelse
                    </div>
                </div>

                <!-- Description & Highlights -->
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 text-xs">
                    <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                        <h4 class="font-bold text-zinc-900 dark:text-white uppercase tracking-wider mb-2">Description</h4>
                        <p class="text-zinc-600 dark:text-zinc-300 whitespace-pre-line leading-relaxed">{{ $prod->description }}</p>
                    </div>

                    <div class="space-y-4">
                        @if (! empty($prod->highlights))
                            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                                <h4 class="font-bold text-zinc-900 dark:text-white uppercase tracking-wider mb-2">Highlights</h4>
                                <ul class="list-disc list-inside space-y-1 text-zinc-600 dark:text-zinc-300">
                                    @foreach ($prod->highlights as $hl)
                                        @if (filled($hl))
                                            <li>{{ $hl }}</li>
                                        @endif
                                    @endforeach
                                </ul>
                            </div>
                        @endif

                        @if (! empty($prod->specs))
                            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800">
                                <h4 class="font-bold text-zinc-900 dark:text-white uppercase tracking-wider mb-2">Technical Specifications</h4>
                                <dl class="grid grid-cols-2 gap-2">
                                    @foreach ($prod->specs as $k => $v)
                                        <div>
                                            <dt class="text-zinc-400 capitalize">{{ str_replace('_', ' ', $k) }}</dt>
                                            <dd class="font-medium text-zinc-800 dark:text-zinc-200">{{ is_array($v) ? implode(', ', $v) : $v }}</dd>
                                        </div>
                                    @endforeach
                                </dl>
                            </div>
                        @endif
                    </div>
                </div>

                <!-- Variants & Listings Table -->
                <div>
                    <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-500 mb-2">
                        Variant Combinations & Pricing ({{ $prod->variants->count() }})
                    </h4>
                    <div class="overflow-x-auto rounded-xl border border-zinc-200 dark:border-zinc-800">
                        <table class="w-full text-left text-xs">
                            <thead class="border-b border-zinc-200 bg-zinc-50 dark:border-zinc-800 dark:bg-zinc-800/50 text-zinc-500 uppercase font-semibold">
                                <tr>
                                    <th class="px-3 py-2">UPID</th>
                                    <th class="px-3 py-2">Variant Attributes</th>
                                    <th class="px-3 py-2">SKU</th>
                                    <th class="px-3 py-2 text-right">MRP</th>
                                    <th class="px-3 py-2 text-right">Selling Price</th>
                                    <th class="px-3 py-2 text-center">Stock</th>
                                    <th class="px-3 py-2">Barcode</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                @foreach ($prod->variants as $var)
                                    @php
                                        $listing = $var->listings->first();
                                    @endphp
                                    <tr>
                                        <td class="px-3 py-2 font-mono text-[11px] font-bold text-zinc-900 dark:text-white">
                                            {{ $var->upid }}
                                        </td>
                                        <td class="px-3 py-2 font-medium text-zinc-800 dark:text-zinc-200">
                                            @if (is_array($var->variant_attributes))
                                                @foreach ($var->variant_attributes as $attrKey => $attrVal)
                                                    <span class="inline-block rounded bg-zinc-100 dark:bg-zinc-800 px-1.5 py-0.5 text-[10px] mr-1">
                                                        {{ ucfirst($attrKey) }}: <strong>{{ $attrVal }}</strong>
                                                    </span>
                                                @endforeach
                                            @else
                                                {{ $var->variant_key }}
                                            @endif
                                        </td>
                                        <td class="px-3 py-2 font-mono text-zinc-700 dark:text-zinc-300">
                                            {{ $listing?->sku ?? '—' }}
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono text-zinc-500 line-through">
                                            {{ $listing ? '₹'.number_format($listing->mrpInRupees(), 2) : '—' }}
                                        </td>
                                        <td class="px-3 py-2 text-right font-mono font-bold text-emerald-600 dark:text-emerald-400">
                                            {{ $listing ? '₹'.number_format($listing->sellingPriceInRupees(), 2) : '—' }}
                                        </td>
                                        <td class="px-3 py-2 text-center font-bold {{ ($listing?->stock ?? 0) > 0 ? 'text-zinc-900 dark:text-white' : 'text-red-500' }}">
                                            {{ $listing?->stock ?? 0 }}
                                        </td>
                                        <td class="px-3 py-2 font-mono text-zinc-400">
                                            {{ $var->barcode ?: '—' }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- QC Action Panel -->
                <div class="pt-4 border-t border-zinc-200 dark:border-zinc-800 space-y-3">
                    @php
                        $qcIssues = $prod->variantIssues();
                    @endphp

                    @if (session('review_error'))
                        <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
                            {{ session('review_error') }}
                        </div>
                    @endif

                    @if (! empty($qcIssues))
                        <div class="rounded-xl border border-red-300 bg-red-50 p-4 text-xs text-red-900 dark:border-red-900/60 dark:bg-red-950/40 dark:text-red-200 space-y-1">
                            <div class="font-bold flex items-center gap-1.5 text-sm text-red-800 dark:text-red-300">
                                <svg class="size-4 shrink-0 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                </svg>
                                Variant Integrity Issues Detected (Approval Blocked)
                            </div>
                            <ul class="list-disc list-inside space-y-1 mt-1 font-medium">
                                @foreach ($qcIssues as $issue)
                                    <li>{{ $issue['message'] }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300">
                        QC Review Notes (Optional for approval, visible to seller if rejected)
                    </label>
                    <textarea wire:model="review_notes" rows="2" placeholder="e.g. Approved with standard catalog specifications, or note any feedback..."
                              class="w-full rounded-lg border border-zinc-300 p-2 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"></textarea>

                    <div class="flex items-center justify-between pt-2">
                        <button type="button" wire:click="closeReviewModal" class="rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                            Close
                        </button>

                        <div class="flex items-center gap-2">
                            <button type="button" wire:click="openRejectModal({{ $prod->id }})"
                                    class="rounded-lg border border-red-300 bg-red-50 px-4 py-2 text-xs font-semibold text-red-700 hover:bg-red-100 dark:border-red-900 dark:bg-red-950 dark:text-red-300 transition">
                                Reject Product
                            </button>
                            @if (empty($qcIssues))
                                <button type="button" wire:click="approveProduct({{ $prod->id }})"
                                        class="rounded-lg bg-emerald-600 px-5 py-2 text-xs font-semibold text-white hover:bg-emerald-700 transition shadow-xs">
                                    Approve Product & Listings
                                </button>
                            @else
                                <button type="button" disabled title="Resolve variant issues before approving"
                                        class="cursor-not-allowed rounded-lg bg-zinc-300 px-5 py-2 text-xs font-semibold text-zinc-500 dark:bg-zinc-800 dark:text-zinc-500">
                                    Approve Blocked (Has Issues)
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <!-- REJECT MODAL -->
    @if ($showRejectModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="closeRejectModal" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-2xl dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
                <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                    <h3 class="text-base font-bold text-zinc-900 dark:text-white">Reject Product Submission</h3>
                    <button type="button" wire:click="closeRejectModal" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <p class="text-xs text-zinc-600 dark:text-zinc-400">
                    Please provide a clear reason for rejecting this product. The seller will see this feedback so they can fix and resubmit.
                </p>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                        Rejection Reason *
                    </label>
                    <textarea wire:model="rejection_reason" rows="4" required placeholder="e.g. Images are blurry, title violates catalog guidelines, or pricing is inconsistent..."
                              class="w-full rounded-lg border border-zinc-300 p-2.5 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"></textarea>
                    @error('rejection_reason')
                        <p class="text-xs text-red-600 dark:text-red-400 mt-1">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex justify-end gap-2 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                    <button type="button" wire:click="closeRejectModal" class="rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                        Cancel
                    </button>
                    <button type="button" wire:click="confirmReject" class="rounded-lg bg-red-600 px-4 py-2 text-xs font-semibold text-white hover:bg-red-700 transition">
                        Confirm Rejection
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

