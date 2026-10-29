<?php

namespace App\Livewire\Admin;

use App\Models\Product;
use App\Reviews\Enums\ReviewStatus;
use App\Reviews\Models\ProductReview;
use App\Reviews\Services\ProductReviewService;
use App\Services\AdminActivityLogger;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Product Reviews')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $status = 'all';

    #[Url]
    public string $rating = 'all';

    #[Url]
    public string $verified = 'all';

    public bool $showDeleteModal = false;

    public ?int $deletingReviewId = null;

    public bool $showDetailModal = false;

    public ?int $viewingReviewId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', ProductReview::class);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    public function updatingRating(): void
    {
        $this->resetPage();
    }

    public function updatingVerified(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function counts(): array
    {
        return [
            'total' => ProductReview::query()->count(),
            'approved' => ProductReview::query()->where('status', ReviewStatus::Approved)->count(),
            'hidden' => ProductReview::query()->where('status', ReviewStatus::Hidden)->count(),
            'rejected' => ProductReview::query()->where('status', ReviewStatus::Rejected)->count(),
        ];
    }

    #[Computed]
    public function reviews(): LengthAwarePaginator
    {
        $this->authorize('viewAny', ProductReview::class);

        $query = ProductReview::query()
            ->with('user')
            ->when($this->status !== 'all', function ($q) {
                $q->where('status', $this->status);
            })
            ->when($this->rating !== 'all', function ($q) {
                $q->where('rating', (int) $this->rating);
            })
            ->when($this->verified === 'yes', function ($q) {
                $q->where('is_verified_purchase', true);
            })
            ->when($this->verified === 'no', function ($q) {
                $q->where('is_verified_purchase', false);
            })
            ->when(trim($this->search) !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $productIds = Product::query()->where('title', 'like', $term)->pluck('id')->all();

                $q->where(function ($sub) use ($term, $productIds) {
                    $sub->where('author_name', 'like', $term)
                        ->orWhere('title', 'like', $term)
                        ->orWhere('comment', 'like', $term);

                    if (! empty($productIds)) {
                        $sub->orWhereIn('reviewable_id', $productIds);
                    }
                });
            });

        return $query->latest('id')->paginate(15);
    }

    /**
     * @return Collection<int, Product>
     */
    #[Computed]
    public function products(): Collection
    {
        $ids = $this->reviews->pluck('reviewable_id')->unique()->filter()->all();

        if (empty($ids)) {
            return collect();
        }

        return Product::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    #[Computed]
    public function viewingReview(): ?ProductReview
    {
        if (! $this->viewingReviewId) {
            return null;
        }

        return ProductReview::query()->with('user')->find($this->viewingReviewId);
    }

    public function viewDetails(int $reviewId): void
    {
        $this->viewingReviewId = $reviewId;
        $this->showDetailModal = true;
    }

    public function closeDetails(): void
    {
        $this->showDetailModal = false;
        $this->viewingReviewId = null;
    }

    public function hideReview(int $reviewId, ProductReviewService $service): void
    {
        $this->authorize('moderate', ProductReview::class);

        $review = ProductReview::findOrFail($reviewId);
        $oldStatus = $review->status;

        $review = $service->setStatus($reviewId, ReviewStatus::Hidden);

        AdminActivityLogger::log('review.hidden', $review, [
            'product_id' => $review->reviewable_id,
            'previous_status' => $oldStatus->value,
            'new_status' => ReviewStatus::Hidden->value,
        ]);

        session()->flash('success', "Review #{$reviewId} has been hidden and rating summary recalculated.");
    }

    public function restoreReview(int $reviewId, ProductReviewService $service): void
    {
        $this->authorize('moderate', ProductReview::class);

        $review = ProductReview::findOrFail($reviewId);
        $oldStatus = $review->status;

        $review = $service->setStatus($reviewId, ReviewStatus::Approved);

        AdminActivityLogger::log('review.restored', $review, [
            'product_id' => $review->reviewable_id,
            'previous_status' => $oldStatus->value,
            'new_status' => ReviewStatus::Approved->value,
        ]);

        session()->flash('success', "Review #{$reviewId} has been restored to approved and rating summary recalculated.");
    }

    public function rejectReview(int $reviewId, ProductReviewService $service): void
    {
        $this->authorize('moderate', ProductReview::class);

        $review = ProductReview::findOrFail($reviewId);
        $oldStatus = $review->status;

        $review = $service->setStatus($reviewId, ReviewStatus::Rejected);

        AdminActivityLogger::log('review.rejected', $review, [
            'product_id' => $review->reviewable_id,
            'previous_status' => $oldStatus->value,
            'new_status' => ReviewStatus::Rejected->value,
        ]);

        session()->flash('success', "Review #{$reviewId} has been rejected and rating summary recalculated.");
    }

    public function confirmDelete(int $reviewId): void
    {
        $this->authorize('moderate', ProductReview::class);
        $this->deletingReviewId = $reviewId;
        $this->showDeleteModal = true;
    }

    public function cancelDelete(): void
    {
        $this->deletingReviewId = null;
        $this->showDeleteModal = false;
    }

    public function deleteConfirmed(ProductReviewService $service): void
    {
        $this->authorize('moderate', ProductReview::class);

        if (! $this->deletingReviewId) {
            return;
        }

        $review = ProductReview::find($this->deletingReviewId);
        if (! $review) {
            $this->showDeleteModal = false;
            $this->deletingReviewId = null;

            return;
        }

        $reviewId = $review->id;
        $productId = $review->reviewable_id;
        $rating = $review->rating;
        $status = $review->status->value;

        $service->deleteReview($reviewId);

        AdminActivityLogger::log('review.deleted', $review, [
            'product_id' => $productId,
            'rating' => $rating,
            'status' => $status,
        ]);

        $this->showDeleteModal = false;
        $this->deletingReviewId = null;

        session()->flash('success', "Review #{$reviewId} has been permanently deleted and rating summary recalculated.");
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Product Reviews</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">Moderate customer reviews, inspect verified purchases, and manage public rating visibility.</p>
        </div>

        <div class="flex items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-700 shadow-2xs dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                <span class="size-2 rounded-full bg-emerald-500"></span>
                <span>Approved: {{ $this->counts['approved'] }}</span>
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-700 shadow-2xs dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                <span class="size-2 rounded-full bg-amber-500"></span>
                <span>Hidden: {{ $this->counts['hidden'] }}</span>
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-700 shadow-2xs dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                <span class="size-2 rounded-full bg-rose-500"></span>
                <span>Rejected: {{ $this->counts['rejected'] }}</span>
            </span>
        </div>
    </div>

    <!-- Flash Messages -->
    @if (session()->has('success'))
        <div class="flex items-center justify-between rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
            <div class="flex items-center gap-2">
                <svg class="size-5 shrink-0 text-emerald-600 dark:text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                </svg>
                <span>{{ session('success') }}</span>
            </div>
        </div>
    @endif
    @if (session()->has('error'))
        <div class="flex items-center justify-between rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800 dark:border-rose-800 dark:bg-rose-950/40 dark:text-rose-300">
            <div class="flex items-center gap-2">
                <svg class="size-5 shrink-0 text-rose-600 dark:text-rose-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                </svg>
                <span>{{ session('error') }}</span>
            </div>
        </div>
    @endif

    <!-- Search & Filters -->
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5 rounded-2xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <!-- Search -->
        <div class="relative lg:col-span-2">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search by product, customer, or comment..."
                class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3.5 py-2 pl-9 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500 dark:focus:border-white"
            />
            <svg class="absolute left-3 top-2.5 size-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
        </div>

        <!-- Status Filter -->
        <div>
            <select
                wire:model.live="status"
                class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-800 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-zinc-200"
            >
                <option value="all">All Statuses</option>
                <option value="approved">Approved</option>
                <option value="hidden">Hidden</option>
                <option value="rejected">Rejected</option>
            </select>
        </div>

        <!-- Rating Filter -->
        <div>
            <select
                wire:model.live="rating"
                class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-800 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-zinc-200"
            >
                <option value="all">All Ratings</option>
                <option value="5">5 Stars</option>
                <option value="4">4 Stars</option>
                <option value="3">3 Stars</option>
                <option value="2">2 Stars</option>
                <option value="1">1 Star</option>
            </select>
        </div>

        <!-- Verified Purchase Filter -->
        <div>
            <select
                wire:model.live="verified"
                class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-800 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-zinc-200"
            >
                <option value="all">All Purchases</option>
                <option value="yes">Verified Only</option>
                <option value="no">Non-Verified</option>
            </select>
        </div>
    </div>

    <!-- Reviews Table -->
    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-400">
                <thead class="border-b border-zinc-200 bg-zinc-50/75 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400">
                    <tr>
                        <th scope="col" class="px-4 py-3.5">Product</th>
                        <th scope="col" class="px-4 py-3.5">Customer</th>
                        <th scope="col" class="px-4 py-3.5">Rating & Review</th>
                        <th scope="col" class="px-4 py-3.5">Status</th>
                        <th scope="col" class="px-4 py-3.5">Date</th>
                        <th scope="col" class="px-4 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($this->reviews as $review)
                        @php
                            $product = $this->products->get($review->reviewable_id);
                        @endphp
                        <tr wire:key="review-{{ $review->id }}" class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30 transition">
                            <!-- Product Column -->
                            <td class="px-4 py-3.5 max-w-[220px]">
                                @if ($product)
                                    <div class="font-medium text-zinc-900 dark:text-white line-clamp-1" title="{{ $product->title }}">
                                        {{ $product->title }}
                                    </div>
                                    <div class="text-[11px] text-zinc-400">
                                        ID: #{{ $product->id }}
                                        @if ($review->product_variant_id)
                                            · Variant #{{ $review->product_variant_id }}
                                        @endif
                                    </div>
                                @else
                                    <span class="font-medium text-zinc-500">Product #{{ $review->reviewable_id }}</span>
                                @endif
                            </td>

                            <!-- Customer Column -->
                            <td class="px-4 py-3.5 whitespace-nowrap">
                                <div class="font-medium text-zinc-900 dark:text-white">
                                    {{ $review->author_name }}
                                </div>
                                <div class="flex items-center gap-1.5 mt-0.5">
                                    @if ($review->is_verified_purchase)
                                        <span class="inline-flex items-center gap-0.5 text-[10px] font-semibold text-emerald-700 bg-emerald-50 px-1.5 py-0.5 rounded-full dark:bg-emerald-950/60 dark:text-emerald-300">
                                            <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                                            </svg>
                                            Verified
                                        </span>
                                    @endif
                                    @if ($review->user)
                                        <span class="text-[11px] text-zinc-400 truncate max-w-[120px]" title="{{ $review->user->email }}">{{ $review->user->email }}</span>
                                    @else
                                        <span class="text-[10px] text-zinc-400 italic">No account</span>
                                    @endif
                                </div>
                            </td>

                            <!-- Rating & Content Column -->
                            <td class="px-4 py-3.5 max-w-sm">
                                <div class="flex items-center gap-1">
                                    <div class="flex items-center text-amber-400">
                                        @for ($i = 1; $i <= 5; $i++)
                                            @if ($i <= $review->rating)
                                                <svg class="size-3.5 fill-current" viewBox="0 0 20 20">
                                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                                </svg>
                                            @else
                                                <svg class="size-3.5 text-zinc-300 dark:text-zinc-600 fill-current" viewBox="0 0 20 20">
                                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                                </svg>
                                            @endif
                                        @endfor
                                    </div>
                                    <span class="text-xs font-semibold text-zinc-700 dark:text-zinc-300 ml-1">{{ $review->rating }}/5</span>
                                </div>

                                @if ($review->title)
                                    <div class="mt-1 font-medium text-xs text-zinc-900 dark:text-white line-clamp-1">
                                        {{ $review->title }}
                                    </div>
                                @endif

                                <p class="text-xs text-zinc-600 dark:text-zinc-400 mt-0.5 line-clamp-2">
                                    {{ $review->comment }}
                                </p>
                            </td>

                            <!-- Status Column -->
                            <td class="px-4 py-3.5 whitespace-nowrap">
                                @if ($review->status === ReviewStatus::Approved)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-500/10 dark:text-emerald-400 dark:ring-emerald-500/20">
                                        <span class="size-1.5 rounded-full bg-emerald-500"></span>
                                        Approved
                                    </span>
                                @elseif ($review->status === ReviewStatus::Hidden)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-amber-600/20 dark:bg-amber-500/10 dark:text-amber-400 dark:ring-amber-500/20">
                                        <span class="size-1.5 rounded-full bg-amber-500"></span>
                                        Hidden
                                    </span>
                                @elseif ($review->status === ReviewStatus::Rejected)
                                    <span class="inline-flex items-center gap-1 rounded-full bg-rose-50 px-2 py-0.5 text-xs font-semibold text-rose-700 ring-1 ring-rose-600/20 dark:bg-rose-500/10 dark:text-rose-400 dark:ring-rose-500/20">
                                        <span class="size-1.5 rounded-full bg-rose-500"></span>
                                        Rejected
                                    </span>
                                @endif
                            </td>

                            <!-- Date Column -->
                            <td class="px-4 py-3.5 whitespace-nowrap text-xs text-zinc-500 dark:text-zinc-400">
                                @datetime($review->created_at)
                            </td>

                            <!-- Actions Column -->
                            <td class="px-4 py-3.5 text-right whitespace-nowrap">
                                <div class="inline-flex items-center gap-1">
                                    <!-- View Details -->
                                    <button
                                        type="button"
                                        wire:click="viewDetails({{ $review->id }})"
                                        class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-800 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-zinc-200 transition"
                                        title="View Details"
                                    >
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                    </button>

                                    @can('moderate', \App\Reviews\Models\ProductReview::class)
                                        @if ($review->status === ReviewStatus::Approved)
                                            <!-- Hide -->
                                            <button
                                                type="button"
                                                wire:click="hideReview({{ $review->id }})"
                                                wire:confirm="Hide this review from public display? Public rating will be recalculated immediately."
                                                class="rounded-lg px-2 py-1 text-xs font-medium text-amber-700 hover:bg-amber-50 dark:text-amber-400 dark:hover:bg-amber-950/40 transition"
                                                title="Hide review"
                                            >
                                                Hide
                                            </button>
                                            <!-- Reject -->
                                            <button
                                                type="button"
                                                wire:click="rejectReview({{ $review->id }})"
                                                wire:confirm="Reject this review? Public rating will be recalculated immediately."
                                                class="rounded-lg px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-950/40 transition"
                                                title="Reject review"
                                            >
                                                Reject
                                            </button>
                                        @elseif ($review->status === ReviewStatus::Hidden)
                                            <!-- Restore -->
                                            <button
                                                type="button"
                                                wire:click="restoreReview({{ $review->id }})"
                                                class="rounded-lg px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950/40 transition"
                                                title="Restore to approved"
                                            >
                                                Restore
                                            </button>
                                            <!-- Reject -->
                                            <button
                                                type="button"
                                                wire:click="rejectReview({{ $review->id }})"
                                                wire:confirm="Reject this review?"
                                                class="rounded-lg px-2 py-1 text-xs font-medium text-rose-700 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-950/40 transition"
                                                title="Reject review"
                                            >
                                                Reject
                                            </button>
                                        @elseif ($review->status === ReviewStatus::Rejected)
                                            <!-- Restore -->
                                            <button
                                                type="button"
                                                wire:click="restoreReview({{ $review->id }})"
                                                class="rounded-lg px-2 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50 dark:text-emerald-400 dark:hover:bg-emerald-950/40 transition"
                                                title="Restore to approved"
                                            >
                                                Restore
                                            </button>
                                        @endif

                                        <!-- Delete -->
                                        <button
                                            type="button"
                                            wire:click="confirmDelete({{ $review->id }})"
                                            class="rounded-lg p-1.5 text-zinc-400 hover:bg-rose-50 hover:text-rose-600 dark:hover:bg-rose-950/40 dark:hover:text-rose-400 transition"
                                            title="Delete review"
                                        >
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center">
                                <div class="mx-auto flex size-12 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800 text-zinc-400">
                                    <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11.049 2.927c.3-.921 1.603-.921 1.902 0l1.519 4.674a1 1 0 00.95.69h4.915c.969 0 1.371 1.24.588 1.81l-3.976 2.888a1 1 0 00-.363 1.118l1.518 4.674c.3.922-.755 1.688-1.538 1.118l-3.976-2.888a1 1 0 00-1.176 0l-3.976 2.888c-.783.57-1.838-.197-1.538-1.118l1.518-4.674a1 1 0 00-.363-1.118l-3.976-2.888c-.784-.57-.38-1.81.588-1.81h4.914a1 1 0 00.951-.69l1.519-4.674z"/>
                                    </svg>
                                </div>
                                <h3 class="mt-2 text-sm font-semibold text-zinc-900 dark:text-white">No reviews found</h3>
                                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                                    @if ($this->search || $this->status !== 'all' || $this->rating !== 'all' || $this->verified !== 'all')
                                        Try adjusting your search filters.
                                    @else
                                        Customer reviews will appear here once submitted.
                                    @endif
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->reviews->hasPages())
            <div class="border-t border-zinc-200 px-4 py-3 dark:border-zinc-800">
                {{ $this->reviews->links() }}
            </div>
        @endif
    </div>

    <!-- Review Details Modal -->
    @if ($showDetailModal && $this->viewingReview)
        @php
            $viewReview = $this->viewingReview;
            $viewProduct = $this->products->get($viewReview->reviewable_id);
        @endphp
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-zinc-900/60 backdrop-blur-xs">
            <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900 dark:border dark:border-zinc-800 space-y-4">
                <div class="flex items-start justify-between">
                    <div>
                        <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Review Details #{{ $viewReview->id }}</h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Submitted on @datetime($viewReview->created_at)</p>
                    </div>
                    <button type="button" wire:click="closeDetails" class="rounded-lg p-1.5 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="space-y-3 text-xs border-y border-zinc-200 py-4 dark:border-zinc-800">
                    <div>
                        <span class="text-zinc-400 uppercase font-semibold text-[10px]">Product</span>
                        <div class="text-sm font-semibold text-zinc-900 dark:text-white mt-0.5">
                            {{ $viewProduct ? $viewProduct->title : 'Product #'.$viewReview->reviewable_id }}
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-2">
                        <div>
                            <span class="text-zinc-400 uppercase font-semibold text-[10px]">Author</span>
                            <div class="font-medium text-zinc-900 dark:text-white mt-0.5">{{ $viewReview->author_name }}</div>
                            @if ($viewReview->user)
                                <div class="text-zinc-500">{{ $viewReview->user->email }}</div>
                            @endif
                        </div>
                        <div>
                            <span class="text-zinc-400 uppercase font-semibold text-[10px]">Purchase Status</span>
                            <div class="mt-0.5">
                                @if ($viewReview->is_verified_purchase)
                                    <span class="inline-flex items-center gap-1 font-semibold text-emerald-700 bg-emerald-50 px-2 py-0.5 rounded-full dark:bg-emerald-950/60 dark:text-emerald-300">
                                        Verified Purchase
                                    </span>
                                @else
                                    <span class="text-zinc-500">Unverified</span>
                                @endif
                            </div>
                        </div>
                    </div>

                    <div>
                        <span class="text-zinc-400 uppercase font-semibold text-[10px]">Rating</span>
                        <div class="flex items-center gap-1 text-amber-400 mt-0.5">
                            @for ($i = 1; $i <= 5; $i++)
                                <svg class="size-4 {{ $i <= $viewReview->rating ? 'fill-current' : 'text-zinc-300 dark:text-zinc-700 fill-current' }}" viewBox="0 0 20 20">
                                    <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                </svg>
                            @endfor
                            <span class="text-xs font-semibold text-zinc-800 dark:text-zinc-200 ml-1">{{ $viewReview->rating }} out of 5</span>
                        </div>
                    </div>

                    @if ($viewReview->title)
                        <div>
                            <span class="text-zinc-400 uppercase font-semibold text-[10px]">Headline</span>
                            <div class="text-sm font-semibold text-zinc-900 dark:text-white mt-0.5">{{ $viewReview->title }}</div>
                        </div>
                    @endif

                    <div>
                        <span class="text-zinc-400 uppercase font-semibold text-[10px]">Full Comment</span>
                        <div class="mt-1 rounded-xl bg-zinc-50 p-3 text-xs leading-relaxed text-zinc-700 dark:bg-zinc-800/60 dark:text-zinc-300 whitespace-pre-line">
                            {{ $viewReview->comment }}
                        </div>
                    </div>

                    @if ($viewReview->order_item_id || $viewReview->product_variant_id || $viewReview->seller_id)
                        <div class="grid grid-cols-3 gap-2 pt-1 text-[11px] text-zinc-500">
                            @if ($viewReview->order_item_id)
                                <div><span class="font-medium text-zinc-400">Order Item:</span> #{{ $viewReview->order_item_id }}</div>
                            @endif
                            @if ($viewReview->product_variant_id)
                                <div><span class="font-medium text-zinc-400">Variant:</span> #{{ $viewReview->product_variant_id }}</div>
                            @endif
                            @if ($viewReview->seller_id)
                                <div><span class="font-medium text-zinc-400">Seller:</span> #{{ $viewReview->seller_id }}</div>
                            @endif
                        </div>
                    @endif
                </div>

                <div class="flex items-center justify-end">
                    <button
                        type="button"
                        wire:click="closeDetails"
                        class="rounded-xl border border-zinc-200 bg-white px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                    >
                        Close
                    </button>
                </div>
            </div>
        </div>
    @endif

    <!-- Delete Confirmation Modal -->
    @if ($showDeleteModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-zinc-900/60 backdrop-blur-xs">
            <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-xl dark:bg-zinc-900 dark:border dark:border-zinc-800 space-y-4">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-full bg-rose-100 dark:bg-rose-950/60 text-rose-600 dark:text-rose-400">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                        </svg>
                    </div>
                    <div>
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white">Delete Review</h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-0.5">Are you sure you want to permanently delete review #{{ $deletingReviewId }}?</p>
                    </div>
                </div>

                <div class="rounded-xl bg-rose-50 p-3 text-xs text-rose-800 dark:bg-rose-950/40 dark:text-rose-300">
                    This action is permanent and cannot be undone. The product's rating average, total review count, and star distribution will be recalculated immediately.
                </div>

                <div class="flex items-center justify-end gap-3 pt-2">
                    <button
                        type="button"
                        wire:click="cancelDelete"
                        class="rounded-xl border border-zinc-200 bg-white px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        wire:click="deleteConfirmed"
                        class="rounded-xl bg-rose-600 px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-rose-500 transition"
                    >
                        Delete Permanently
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

