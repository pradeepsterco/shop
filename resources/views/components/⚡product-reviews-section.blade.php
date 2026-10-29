<?php

use App\Reviews\DTOs\EligibilityResult;
use App\Reviews\DTOs\ReviewSubmissionData;
use App\Reviews\Enums\ReviewStatus;
use App\Reviews\Models\ProductReview;
use App\Reviews\Models\ReviewSummary;
use App\Reviews\Services\ProductReviewService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component {
    use WithPagination;

    public int $productId;
    public string $sort = 'newest';
    public string $ratingFilter = 'all';
    public bool $showForm = false;

    // Form attributes
    public int $rating = 5;
    public string $title = '';
    public string $comment = '';
    public ?int $editingReviewId = null;
    public ?string $feedbackMessage = null;
    public ?string $errorMessage = null;

    public function mount(int $productId): void
    {
        $this->productId = $productId;
    }

    #[Computed]
    public function isEnabled(): bool
    {
        return (bool) config('reviews.enabled', true);
    }

    #[Computed]
    public function summary(): ?ReviewSummary
    {
        if (! $this->isEnabled) {
            return null;
        }

        return app(ProductReviewService::class)->getSummary($this->productId);
    }

    #[Computed]
    public function eligibility(): ?EligibilityResult
    {
        if (! $this->isEnabled || ! auth()->check()) {
            return null;
        }

        return app(ProductReviewService::class)->checkEligibility(auth()->user(), $this->productId);
    }

    #[Computed]
    public function reviews(): LengthAwarePaginator
    {
        $query = ProductReview::query()
            ->where('reviewable_type', 'product')
            ->where('reviewable_id', $this->productId)
            ->where('status', ReviewStatus::Approved->value);

        if ($this->ratingFilter !== 'all') {
            $query->where('rating', (int) $this->ratingFilter);
        }

        match ($this->sort) {
            'highest' => $query->orderByDesc('rating')->orderByDesc('created_at'),
            'lowest' => $query->orderBy('rating')->orderByDesc('created_at'),
            default => $query->orderByDesc('created_at'),
        };

        return $query->paginate((int) config('reviews.per_page', 5));
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function setRatingFilter(string $rating): void
    {
        $this->ratingFilter = $rating;
        $this->resetPage();
    }

    public function openReviewForm(): void
    {
        if (! auth()->check()) {
            $this->redirect(route('login'));
            return;
        }

        $this->resetErrorBag();
        $this->errorMessage = null;
        $this->feedbackMessage = null;

        $eligibility = $this->eligibility;
        if (! $eligibility || ! $eligibility->canReview) {
            $this->errorMessage = $eligibility?->reason ?? 'Only verified buyers who have received this product can write a review.';
            return;
        }

        if ($eligibility->hasExistingReview && $eligibility->existingReviewId) {
            /** @var ProductReview|null $existing */
            $existing = ProductReview::find($eligibility->existingReviewId);
            if ($existing) {
                $this->editingReviewId = $existing->id;
                $this->rating = $existing->rating;
                $this->title = $existing->title ?? '';
                $this->comment = $existing->comment;
            }
        } else {
            $this->editingReviewId = null;
            $this->rating = 5;
            $this->title = '';
            $this->comment = '';
        }

        $this->showForm = true;
    }

    public function closeReviewForm(): void
    {
        $this->showForm = false;
        $this->resetErrorBag();
        $this->errorMessage = null;
    }

    public function saveReview(): void
    {
        if (! auth()->check()) {
            $this->redirect(route('login'));
            return;
        }

        $this->validate([
            'rating' => 'required|integer|min:1|max:5',
            'title' => 'nullable|string|max:120',
            'comment' => 'required|string|min:10|max:2000',
        ], [
            'rating.min' => 'Please select a star rating between 1 and 5.',
            'rating.max' => 'Please select a star rating between 1 and 5.',
            'comment.min' => 'Your review must be at least 10 characters long.',
            'comment.max' => 'Your review cannot exceed 2000 characters.',
            'title.max' => 'Title cannot exceed 120 characters.',
        ]);

        try {
            $service = app(ProductReviewService::class);
            $data = new ReviewSubmissionData(
                rating: $this->rating,
                comment: $this->comment,
                title: filled($this->title) ? $this->title : null,
            );

            $service->submitOrUpdate(auth()->user(), $this->productId, $data);

            $this->showForm = false;
            $this->feedbackMessage = $this->editingReviewId
                ? 'Your review has been updated successfully.'
                : 'Thank you! Your review has been published.';

            unset($this->summary);
            unset($this->eligibility);
            unset($this->reviews);
            $this->resetPage();
        } catch (\DomainException $e) {
            $this->errorMessage = $e->getMessage();
        }
    }
}; ?>

<section id="reviews" class="{{ $this->isEnabled ? 'mt-16 pt-10 border-t border-zinc-200 dark:border-zinc-800' : '' }}" data-test="reviews-section">
@if ($this->isEnabled)
        <div class="space-y-8">
            <!-- Header & Action -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
                <div>
                    <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-3xl">
                        Customer Reviews
                    </h2>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        Ratings and genuine feedback from verified buyers.
                    </p>
                </div>

                <div>
                    @auth
                        @if ($this->eligibility?->hasExistingReview)
                            <button
                                type="button"
                                wire:click="openReviewForm"
                                data-test="edit-review-button"
                                class="inline-flex items-center gap-2 rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-amber-600 transition"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0 1 15.75 21H5.25A2.25 2.25 0 0 1 3 18.75V8.25A2.25 2.25 0 0 1 5.25 6H10" />
                                </svg>
                                Edit Your Review
                            </button>
                        @elseif ($this->eligibility?->canReview)
                            <button
                                type="button"
                                wire:click="openReviewForm"
                                data-test="write-review-button"
                                class="inline-flex items-center gap-2 rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-amber-600 transition"
                            >
                                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                                </svg>
                                Write a Review
                            </button>
                        @else
                            <div class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-1.5 text-xs font-medium text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-400" title="Only customers with a delivered order for this product can write a review.">
                                <svg class="h-4 w-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75 11.25 15 15 9.75m-3-7.036A11.959 11.959 0 0 1 3.598 6 11.99 11.99 0 0 0 3 9.749c0 5.592 3.824 10.29 9 11.623 5.176-1.332 9-6.03 9-11.622 0-1.31-.21-2.571-.598-3.751h-.152c-3.196 0-6.1-1.248-8.25-3.285Z" />
                                </svg>
                                Verified Buyers Only
                            </div>
                        @endif
                    @else
                        <a
                            href="{{ route('login') }}"
                            class="inline-flex items-center gap-2 rounded-xl border border-zinc-300 bg-white px-4 py-2.5 text-sm font-semibold text-zinc-700 shadow-xs hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200 dark:hover:bg-zinc-700 transition"
                        >
                            Sign in to review
                        </a>
                    @endauth
                </div>
            </div>

            <!-- Feedback Notifications -->
            @if ($this->feedbackMessage)
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/50 dark:text-emerald-300 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg class="h-5 w-5 text-emerald-600" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                        </svg>
                        <span>{{ $this->feedbackMessage }}</span>
                    </div>
                    <button type="button" wire:click="$set('feedbackMessage', null)" class="text-emerald-600 hover:text-emerald-800 dark:text-emerald-400">
                        &times;
                    </button>
                </div>
            @endif

            @if ($this->errorMessage)
                <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/50 dark:text-red-300 flex items-center justify-between">
                    <div class="flex items-center gap-2">
                        <svg class="h-5 w-5 text-red-600" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75 0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
                        </svg>
                        <span>{{ $this->errorMessage }}</span>
                    </div>
                    <button type="button" wire:click="$set('errorMessage', null)" class="text-red-600 hover:text-red-800 dark:text-red-400">
                        &times;
                    </button>
                </div>
            @endif

            <!-- Modal / Submission Form -->
            @if ($this->showForm)
                <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-zinc-900/60 backdrop-blur-xs" data-test="review-form-modal">
                    <div class="w-full max-w-lg rounded-2xl border border-zinc-200 bg-white p-6 shadow-2xl dark:border-zinc-800 dark:bg-zinc-900 space-y-6">
                        <div class="flex items-center justify-between border-b border-zinc-100 pb-4 dark:border-zinc-800">
                            <h3 class="text-lg font-bold text-zinc-900 dark:text-white">
                                {{ $this->editingReviewId ? 'Edit Your Review' : 'Write a Review' }}
                            </h3>
                            <button type="button" wire:click="closeReviewForm" class="rounded-lg p-1 text-zinc-400 hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800">
                                <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <form wire:submit="saveReview" class="space-y-4">
                            <!-- Star Selection -->
                            <div>
                                <label class="block text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-2">
                                    Rating <span class="text-red-500">*</span>
                                </label>
                                <div class="flex items-center gap-1" data-test="star-rating-selector">
                                    @for ($i = 1; $i <= 5; $i++)
                                        <button
                                            type="button"
                                            wire:click="$set('rating', {{ $i }})"
                                            class="p-1 focus:outline-hidden transition transform hover:scale-110"
                                            aria-label="Rate {{ $i }} stars"
                                        >
                                            <svg class="h-8 w-8 {{ $i <= $this->rating ? 'text-amber-400 fill-amber-400' : 'text-zinc-300 fill-transparent dark:text-zinc-700' }}" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5z" />
                                            </svg>
                                        </button>
                                    @endfor
                                    <span class="ml-2 text-xs font-medium text-zinc-500 dark:text-zinc-400">
                                        {{ match ($this->rating) {
                                            1 => '1 - Terrible',
                                            2 => '2 - Poor',
                                            3 => '3 - Average',
                                            4 => '4 - Very Good',
                                            5 => '5 - Excellent',
                                            default => ''
                                        } }}
                                    </span>
                                </div>
                                @error('rating')
                                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Title -->
                            <div>
                                <label for="review-title" class="block text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-1">
                                    Title <span class="text-zinc-400 font-normal lowercase">(optional)</span>
                                </label>
                                <input
                                    id="review-title"
                                    type="text"
                                    wire:model="title"
                                    maxlength="120"
                                    placeholder="e.g. Outstanding battery life and sound quality"
                                    class="w-full rounded-xl border border-zinc-200 bg-white px-3.5 py-2.5 text-sm text-zinc-900 placeholder-zinc-400 focus:border-amber-500 focus:ring-2 focus:ring-amber-500/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                                />
                                @error('title')
                                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Comment -->
                            <div>
                                <label for="review-comment" class="block text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-1">
                                    Review <span class="text-red-500">*</span>
                                </label>
                                <textarea
                                    id="review-comment"
                                    wire:model="comment"
                                    rows="4"
                                    minlength="10"
                                    maxlength="2000"
                                    placeholder="Share your experience with this product..."
                                    class="w-full rounded-xl border border-zinc-200 bg-white px-3.5 py-2.5 text-sm text-zinc-900 placeholder-zinc-400 focus:border-amber-500 focus:ring-2 focus:ring-amber-500/20 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                                ></textarea>
                                <div class="mt-1 flex items-center justify-between text-xs text-zinc-400">
                                    <span>Min. 10 characters</span>
                                    <span>{{ mb_strlen($this->comment) }}/2000</span>
                                </div>
                                @error('comment')
                                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </div>

                            <!-- Actions -->
                            <div class="flex items-center justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                                <button
                                    type="button"
                                    wire:click="closeReviewForm"
                                    class="rounded-xl border border-zinc-200 px-4 py-2 text-sm font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition"
                                >
                                    Cancel
                                </button>
                                <button
                                    type="submit"
                                    wire:loading.attr="disabled"
                                    data-test="submit-review-button"
                                    class="inline-flex items-center gap-2 rounded-xl bg-amber-500 px-5 py-2 text-sm font-semibold text-white shadow-xs hover:bg-amber-600 disabled:opacity-50 transition"
                                >
                                    <span wire:loading.remove wire:target="saveReview">
                                        {{ $this->editingReviewId ? 'Update Review' : 'Submit Review' }}
                                    </span>
                                    <span wire:loading wire:target="saveReview" class="inline-flex items-center gap-1.5">
                                        <svg class="h-4 w-4 animate-spin" viewBox="0 0 24 24" fill="none">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                                        </svg>
                                        Saving...
                                    </span>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            @endif

            <!-- Summary Breakdown & Filter Bar -->
            @php
                $summary = $this->summary;
                $hasReviews = $summary && $summary->rating_count > 0;
            @endphp

            @if ($hasReviews)
                <div class="grid grid-cols-1 lg:grid-cols-3 gap-6 rounded-2xl border border-zinc-200 bg-zinc-50/50 p-6 dark:border-zinc-800 dark:bg-zinc-900/50" data-test="reviews-summary-card">
                    <!-- Left: Average rating -->
                    <div class="flex flex-col items-center justify-center text-center p-4 border-b lg:border-b-0 lg:border-r border-zinc-200 dark:border-zinc-800">
                        <div class="text-5xl font-extrabold text-zinc-900 dark:text-white" data-test="summary-rating-avg">
                            {{ number_format($summary->rating_avg, 1) }}
                        </div>
                        <div class="mt-2 flex items-center gap-1">
                            @for ($s = 1; $s <= 5; $s++)
                                <svg class="h-5 w-5 {{ $s <= round($summary->rating_avg) ? 'text-amber-400 fill-amber-400' : 'text-zinc-300 fill-zinc-200 dark:text-zinc-700 dark:fill-zinc-800' }}" viewBox="0 0 20 20" fill="currentColor">
                                    <path fill-rule="evenodd" d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0l-1.83 4.401-4.753.381c-.833.067-1.171 1.107-.536 1.651l3.62 3.102-1.106 4.637c-.194.813.691 1.456 1.405 1.02L10 15.591l4.069 2.485c.713.436 1.598-.207 1.404-1.02l-1.106-4.637 3.62-3.102c.635-.544.297-1.584-.536-1.65l-4.752-.382-1.831-4.401z" clip-rule="evenodd" />
                                </svg>
                            @endfor
                        </div>
                        <p class="mt-1 text-sm font-medium text-zinc-600 dark:text-zinc-400" data-test="summary-rating-count">
                            Based on {{ $summary->rating_count }} {{ Str::plural('review', $summary->rating_count) }}
                        </p>
                    </div>

                    <!-- Center: 1-5 Star Breakdown Bars -->
                    <div class="lg:col-span-2 flex flex-col justify-center space-y-2.5 p-2">
                        @foreach ([5, 4, 3, 2, 1] as $star)
                            @php
                                $percent = $summary->getStarPercentage($star);
                                $count = match ($star) {
                                    5 => $summary->star_5,
                                    4 => $summary->star_4,
                                    3 => $summary->star_3,
                                    2 => $summary->star_2,
                                    1 => $summary->star_1,
                                    default => 0,
                                };
                                $isActive = $this->ratingFilter === (string) $star;
                            @endphp
                            <button
                                type="button"
                                wire:click="setRatingFilter('{{ $isActive ? 'all' : $star }}')"
                                class="group flex items-center gap-3 text-xs text-left w-full rounded-lg px-2 py-1 transition hover:bg-zinc-100 dark:hover:bg-zinc-800 {{ $isActive ? 'bg-amber-50 dark:bg-amber-950/40 ring-1 ring-amber-500/30' : '' }}"
                                title="Filter by {{ $star }} star reviews"
                            >
                                <span class="w-12 font-semibold text-zinc-700 dark:text-zinc-300 flex items-center gap-1">
                                    {{ $star }} <span class="text-amber-400">★</span>
                                </span>
                                <div class="flex-1 h-2.5 rounded-full bg-zinc-200 dark:bg-zinc-700 overflow-hidden">
                                    <div
                                        class="h-full bg-amber-400 transition-all duration-300"
                                        style="width: {{ $percent }}%;"
                                    ></div>
                                </div>
                                <span class="w-12 text-right font-medium text-zinc-500 dark:text-zinc-400">
                                    {{ $count }}
                                </span>
                            </button>
                        @endforeach
                    </div>
                </div>

                <!-- Controls: Filter & Sort -->
                <div class="flex flex-wrap items-center justify-between gap-4 border-b border-zinc-100 pb-4 dark:border-zinc-800">
                    <div class="flex items-center gap-2">
                        <span class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Filter:</span>
                        <div class="flex flex-wrap gap-1.5">
                            <button
                                type="button"
                                wire:click="setRatingFilter('all')"
                                class="rounded-lg px-3 py-1 text-xs font-semibold transition {{ $this->ratingFilter === 'all' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}"
                            >
                                All ({{ $summary->rating_count }})
                            </button>
                            @foreach ([5, 4, 3, 2, 1] as $star)
                                @php
                                    $starCount = match ($star) {
                                        5 => $summary->star_5,
                                        4 => $summary->star_4,
                                        3 => $summary->star_3,
                                        2 => $summary->star_2,
                                        1 => $summary->star_1,
                                        default => 0,
                                    };
                                @endphp
                                @if ($starCount > 0)
                                    <button
                                        type="button"
                                        wire:click="setRatingFilter('{{ $star }}')"
                                        class="rounded-lg px-2.5 py-1 text-xs font-semibold transition {{ $this->ratingFilter === (string) $star ? 'bg-amber-500 text-white' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}"
                                    >
                                        {{ $star }} ★ ({{ $starCount }})
                                    </button>
                                @endif
                            @endforeach
                        </div>
                    </div>

                    <div class="flex items-center gap-2">
                        <label for="review-sort" class="text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Sort by:</label>
                        <select
                            id="review-sort"
                            wire:model.live="sort"
                            class="rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-800 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                        >
                            <option value="newest">Newest first</option>
                            <option value="highest">Highest rating</option>
                            <option value="lowest">Lowest rating</option>
                        </select>
                    </div>
                </div>
            @endif

            <!-- Reviews List -->
            @php $reviewList = $this->reviews; @endphp
            @if ($reviewList->count() > 0)
                <div class="space-y-6 divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($reviewList as $review)
                        <div class="pt-6 first:pt-0 space-y-2.5" data-test="review-item-{{ $review->id }}">
                            <div class="flex items-start justify-between gap-4">
                                <div class="space-y-1">
                                    <div class="flex items-center gap-2">
                                        <!-- Rating Stars -->
                                        <div class="flex items-center gap-0.5">
                                            @for ($s = 1; $s <= 5; $s++)
                                                <svg class="h-4 w-4 {{ $s <= $review->rating ? 'text-amber-400 fill-amber-400' : 'text-zinc-200 fill-zinc-200 dark:text-zinc-700 dark:fill-zinc-700' }}" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd" d="M10.868 2.884c-.321-.772-1.415-.772-1.736 0l-1.83 4.401-4.753.381c-.833.067-1.171 1.107-.536 1.651l3.62 3.102-1.106 4.637c-.194.813.691 1.456 1.405 1.02L10 15.591l4.069 2.485c.713.436 1.598-.207 1.404-1.02l-1.106-4.637 3.62-3.102c.635-.544.297-1.584-.536-1.65l-4.752-.382-1.831-4.401z" clip-rule="evenodd" />
                                                </svg>
                                            @endfor
                                        </div>

                                        @if ($review->title)
                                            <h4 class="font-bold text-sm text-zinc-900 dark:text-white">
                                                {{ $review->title }}
                                            </h4>
                                        @endif
                                    </div>

                                    <div class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
                                        <span class="font-semibold text-zinc-800 dark:text-zinc-200">{{ $review->author_name }}</span>
                                        @if ($review->is_verified_purchase)
                                            <span>&middot;</span>
                                            <span class="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-1.5 py-0.5 text-[11px] font-semibold text-emerald-700 dark:bg-emerald-950/60 dark:text-emerald-300">
                                                <svg class="h-3 w-3" viewBox="0 0 20 20" fill="currentColor">
                                                    <path fill-rule="evenodd" d="M16.704 4.153a.75.75 0 01.143 1.052l-8 10.5a.75.75 0 01-1.127.075l-4.5-4.5a.75.75 0 011.06-1.06l3.894 3.893 7.48-9.817a.75.75 0 011.05-.143z" clip-rule="evenodd" />
                                                </svg>
                                                Verified Purchase
                                            </span>
                                        @endif
                                        <span>&middot;</span>
                                        <span>{{ $review->created_at?->diffForHumans() }}</span>
                                    </div>
                                </div>

                                @if (auth()->check() && (int) $review->user_id === (int) auth()->id())
                                    <button
                                        type="button"
                                        wire:click="openReviewForm"
                                        class="text-xs font-semibold text-amber-600 hover:text-amber-700 dark:text-amber-400 underline"
                                    >
                                        Edit
                                    </button>
                                @endif
                            </div>

                            <p class="text-sm text-zinc-700 dark:text-zinc-300 leading-relaxed whitespace-pre-line">
                                {{ $review->comment }}
                            </p>
                        </div>
                    @endforeach

                    <!-- Pagination -->
                    @if ($reviewList->hasPages())
                        <div class="pt-6">
                            {{ $reviewList->links() }}
                        </div>
                    @endif
                </div>
            @else
                <!-- Empty State -->
                <div class="rounded-2xl border border-dashed border-zinc-300 p-10 text-center dark:border-zinc-700" data-test="reviews-empty-state">
                    <svg class="mx-auto h-12 w-12 text-zinc-400 dark:text-zinc-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.499a.562.562 0 0 1 1.04 0l2.125 5.111a.563.563 0 0 0 .475.345l5.518.442c.499.04.701.663.321.988l-4.204 3.602a.563.563 0 0 0-.182.557l1.285 5.385a.562.562 0 0 1-.84.61l-4.725-2.885a.562.562 0 0 0-.586 0L6.982 20.54a.562.562 0 0 1-.84-.61l1.285-5.386a.562.562 0 0 0-.182-.557l-4.204-3.602a.562.562 0 0 1 .321-.988l5.518-.442a.563.563 0 0 0 .475-.345L11.48 3.5z" />
                    </svg>
                    @if ($this->ratingFilter !== 'all')
                        <h3 class="mt-3 text-sm font-semibold text-zinc-900 dark:text-white">No {{ $this->ratingFilter }}-star reviews</h3>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">There are no reviews matching this filter.</p>
                        <button type="button" wire:click="setRatingFilter('all')" class="mt-3 text-xs font-semibold text-amber-600 hover:underline dark:text-amber-400">
                            Clear filter
                        </button>
                    @else
                        <h3 class="mt-3 text-sm font-semibold text-zinc-900 dark:text-white">No reviews yet</h3>
                        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                            Have you received this item? Be the first verified buyer to share your feedback.
                        </p>
                    @endif
                </div>
            @endif
        </div>
    @endif
</section>
