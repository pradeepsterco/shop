@props(['profile' => null])

@php
    $status = $profile?->status;
@endphp

@if ($profile === null)
    <div data-test="seller-status-no-profile" class="mb-4 rounded-xl border border-amber-200 bg-amber-50/70 p-3.5 text-xs text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
        <div class="flex items-center justify-between font-semibold">
            <span class="flex items-center gap-1.5">
                <span class="inline-block size-2 rounded-full bg-amber-500 animate-pulse"></span>
                Start setting up your store — Step 1 of 5
            </span>
            <span class="text-[11px] text-amber-700 dark:text-amber-300">0%</span>
        </div>
        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-amber-200 dark:bg-amber-900/60" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full rounded-full bg-amber-500 transition-all duration-300" style="width: 0%"></div>
        </div>
        <p class="mt-2 text-[11px] text-amber-800 dark:text-amber-300">
            Next: <span class="font-medium">Store Info</span>
        </p>
        <a href="{{ route('seller.apply') }}"
           wire:navigate
           class="mt-2.5 flex w-full items-center justify-center rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-amber-700 shadow-xs">
            Continue setup
        </a>
    </div>
@elseif ($status === \App\Enums\SellerStatus::Draft)
    @php
        $step = max(1, min(5, (int) ($profile->current_step ?? 1)));
        $pct = (int) round(($step / 5) * 100);
        $stepNames = [
            1 => 'Store Info',
            2 => 'Contact & Address',
            3 => 'Tax & Bank',
            4 => 'Documents',
            5 => 'Review',
        ];
        $nextStepName = $stepNames[$step] ?? 'Store Info';
    @endphp
    <div data-test="seller-status-draft" class="mb-4 rounded-xl border border-amber-200 bg-amber-50/70 p-3.5 text-xs text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/40 dark:text-amber-200">
        <div class="flex items-center justify-between font-semibold">
            <span class="flex items-center gap-1.5">
                <span class="inline-block size-2 rounded-full bg-amber-500 animate-pulse"></span>
                Setup In Progress
            </span>
            <span class="text-[11px] text-amber-700 dark:text-amber-300">Step {{ $step }}/5 ({{ $pct }}%)</span>
        </div>
        <div class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-amber-200 dark:bg-amber-900/60" role="progressbar" aria-valuenow="{{ $pct }}" aria-valuemin="0" aria-valuemax="100">
            <div class="h-full rounded-full bg-amber-500 transition-all duration-300" style="width: {{ $pct }}%"></div>
        </div>
        <p class="mt-2 text-[11px] text-amber-800 dark:text-amber-300">
            Next: <span class="font-medium">{{ $nextStepName }}</span>
        </p>
        <a href="{{ route('seller.apply') }}"
           wire:navigate
           class="mt-2.5 flex w-full items-center justify-center rounded-lg bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-amber-700 shadow-xs">
            Continue setup
        </a>
    </div>
@elseif ($status === \App\Enums\SellerStatus::Pending)
    <div data-test="seller-status-pending" class="mb-4 rounded-xl border border-sky-200 bg-sky-50/70 p-3.5 text-xs text-sky-900 dark:border-sky-900/50 dark:bg-sky-950/40 dark:text-sky-200">
        <div class="flex items-center gap-1.5 font-semibold">
            <svg class="size-4 shrink-0 text-sky-600 dark:text-sky-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
            </svg>
            <span>Under review</span>
        </div>
        <p class="mt-1.5 text-[11px] text-sky-800 dark:text-sky-300 leading-relaxed">
            Your application is being reviewed by our team. We'll notify you once approved.
        </p>
    </div>
@elseif ($status === \App\Enums\SellerStatus::Rejected)
    <div data-test="seller-status-rejected" class="mb-4 rounded-xl border border-red-200 bg-red-50/70 p-3.5 text-xs text-red-900 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-200">
        <div class="flex items-center gap-1.5 font-semibold">
            <svg class="size-4 shrink-0 text-red-600 dark:text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <span>Action needed</span>
        </div>
        @if ($profile?->rejection_reason)
            <p class="mt-1 text-[11px] text-red-800 dark:text-red-300 break-words">
                {{ $profile->rejection_reason }}
            </p>
        @endif
        <a href="{{ route('seller.apply') }}"
           wire:navigate
           class="mt-2.5 flex w-full items-center justify-center rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white transition hover:bg-red-700 shadow-xs">
            Fix & resubmit
        </a>
    </div>
@elseif ($status === \App\Enums\SellerStatus::Suspended)
    <div data-test="seller-status-suspended" class="mb-4 rounded-xl border border-red-200 bg-red-50/70 p-3.5 text-xs text-red-900 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-200">
        <div class="flex items-center gap-1.5 font-semibold text-red-700 dark:text-red-400">
            <svg class="size-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
            </svg>
            <span>Store suspended — contact support</span>
        </div>
        <p class="mt-1 text-[11px] text-red-800/80 dark:text-red-300/80 leading-relaxed">
            Existing orders can still be fulfilled. Product listings and storefront are hidden.
        </p>
    </div>
@endif

