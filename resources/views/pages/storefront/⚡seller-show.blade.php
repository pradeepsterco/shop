<?php

namespace App\Livewire\Storefront;

use App\Enums\SellerStatus;
use App\Models\SellerProfile;
use App\Reviews\Enums\ReviewStatus;
use App\Reviews\Models\ProductReview;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Seller details customers can see before and after buying, as the Consumer Protection (E-Commerce) Rules, 2020
 * require of a marketplace: business name, address, rating. Customers still contact the marketplace, not the seller.
 */
new #[Layout('layouts.storefront')] #[Title('Seller Details')] class extends Component {
    public string $slug;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
        abort_unless($this->seller, 404);
    }

    #[Computed]
    public function seller(): ?SellerProfile
    {
        return SellerProfile::query()
            ->where('store_slug', $this->slug)
            ->whereIn('status', [SellerStatus::Approved->value, SellerStatus::Suspended->value])
            ->first();
    }

    /**
     * @return array{average: float, count: int}
     */
    #[Computed]
    public function rating(): array
    {
        $stats = ProductReview::query()
            ->where('seller_id', $this->seller->id)
            ->where('status', ReviewStatus::Approved->value)
            ->selectRaw('avg(rating) as average, count(*) as total')
            ->first();

        return ['average' => round((float) ($stats->average ?? 0), 1), 'count' => (int) ($stats->total ?? 0)];
    }
}; ?>

@php($seller = $this->seller)

<div class="mx-auto max-w-3xl space-y-6 px-4 py-10 sm:px-6">
    <div class="flex items-center gap-4">
        @if ($seller->logo_path)
            <img src="{{ asset('storage/'.$seller->logo_path) }}" alt="" class="size-16 rounded-xl border border-zinc-200 bg-white object-contain p-1 dark:border-zinc-800" />
        @endif
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Seller details</p>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">{{ $seller->store_name }}</h1>
            @if ($this->rating['count'] > 0)
                <p class="mt-0.5 text-sm text-zinc-600 dark:text-zinc-400">
                    <span class="font-semibold text-amber-600 dark:text-amber-400">★ {{ number_format($this->rating['average'], 1) }}</span>
                    from {{ $this->rating['count'] }} {{ \Illuminate\Support\Str::plural('rating', $this->rating['count']) }}
                </p>
            @else
                <p class="mt-0.5 text-sm text-zinc-500 dark:text-zinc-400">No ratings yet</p>
            @endif
        </div>
    </div>

    @if ($seller->description)
        <p class="text-sm text-zinc-700 dark:text-zinc-300">{{ $seller->description }}</p>
    @endif

    <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <dl class="grid gap-4 text-sm sm:grid-cols-2">
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Registered business name</dt>
                <dd class="mt-1 font-medium text-zinc-900 dark:text-white">{{ $seller->legal_name ?: $seller->store_name }}</dd>
            </div>
            <div>
                <dt class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Business type</dt>
                <dd class="mt-1 text-zinc-700 dark:text-zinc-300">{{ $seller->business_type === 'company' ? 'Registered business' : 'Individual seller' }}</dd>
            </div>
            @if ($seller->gstin)
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wider text-zinc-400">GSTIN</dt>
                    <dd class="mt-1 font-mono text-zinc-700 dark:text-zinc-300">{{ $seller->gstin }}</dd>
                </div>
            @endif
            @if ($seller->approved_at)
                <div>
                    <dt class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Selling on {{ brand_name() }} since</dt>
                    <dd class="mt-1 text-zinc-700 dark:text-zinc-300">{{ format_date($seller->approved_at, 'M Y') }}</dd>
                </div>
            @endif
            <div class="sm:col-span-2">
                <dt class="text-xs font-semibold uppercase tracking-wider text-zinc-400">Registered address</dt>
                <dd class="mt-1 text-zinc-700 dark:text-zinc-300">
                    {{ $seller->pickup_address_line1 }}@if ($seller->pickup_address_line2), {{ $seller->pickup_address_line2 }}@endif<br>
                    {{ $seller->pickup_city }}, {{ $seller->pickup_state }} {{ $seller->pickup_postal_code }}, {{ $seller->pickup_country }}
                </dd>
            </div>
        </dl>
    </div>

    <div class="rounded-2xl border border-zinc-200 bg-white p-6 text-sm text-zinc-700 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
        <h2 class="text-base font-bold text-zinc-900 dark:text-white">Customer care</h2>
        <p class="mt-1">
            For questions or problems with an order from this seller, contact {{ brand_name() }}
            <a href="{{ route('support.create') }}" class="font-semibold text-amber-600 hover:text-amber-700 dark:text-amber-400">Help &amp; Support</a>.
            We handle it with the seller for you. You can also reach our <a href="{{ route('grievance-officer') }}" class="font-semibold text-amber-600 hover:text-amber-700 dark:text-amber-400">Grievance Officer</a>.
        </p>
    </div>
</div>
