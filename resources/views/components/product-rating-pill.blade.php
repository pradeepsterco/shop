@props([
    'summary' => null,
    'showLink' => true,
    'size' => 'md',
])

@if (config('reviews.enabled', true))
    @if ($summary && $summary->rating_count > 0)
        <div class="inline-flex items-center gap-1.5" data-test="product-rating-pill">
            <span class="inline-flex items-center gap-1 rounded-md bg-amber-50 px-2 py-0.5 text-xs font-bold text-amber-900 dark:bg-amber-950/60 dark:text-amber-200 ring-1 ring-amber-500/20">
                <span class="text-amber-500">★</span>
                <span>{{ number_format($summary->rating_avg, 1) }}</span>
            </span>
            @if ($showLink)
                <a href="#reviews" class="text-xs text-zinc-500 hover:text-amber-600 dark:text-zinc-400 dark:hover:text-amber-400 transition underline-offset-2 hover:underline">
                    ({{ $summary->rating_count }} {{ Str::plural('review', $summary->rating_count) }})
                </a>
            @else
                <span class="text-xs text-zinc-400 dark:text-zinc-500">
                    ({{ $summary->rating_count }})
                </span>
            @endif
        </div>
    @elseif ($showLink)
        <a href="#reviews" class="inline-flex items-center gap-1 text-xs text-zinc-400 hover:text-amber-600 dark:text-zinc-500 dark:hover:text-amber-400 transition" data-test="no-reviews-pill">
            <span class="text-zinc-300 dark:text-zinc-600">★</span>
            <span>No reviews yet</span>
        </a>
    @endif
@endif

