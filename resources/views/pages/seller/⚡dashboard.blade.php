<?php

use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.seller')] #[Title('Seller Overview')] class extends Component {
    #[Computed]
    public function sellerProfile(): ?SellerProfile
    {
        /** @var User $user */
        $user = Auth::user();

        return $user->sellerProfile;
    }

    #[Computed]
    public function productStats(): array
    {
        $profile = $this->sellerProfile;
        if (! $profile) {
            return ['total' => 0, 'pending' => 0, 'approved' => 0, 'stock' => 0];
        }

        $base = \App\Models\Product::where(function ($q) use ($profile) {
            $q->where('created_by', $profile->id)
                ->orWhereHas('listings', fn ($l) => $l->where('seller_profile_id', $profile->id));
        });

        return [
            'total' => (clone $base)->count(),
            'pending' => (clone $base)->where('qc_status', \App\Enums\ProductQcStatus::QcPending)->count(),
            'approved' => (clone $base)->where('qc_status', \App\Enums\ProductQcStatus::Approved)->count(),
            'stock' => \App\Models\Listing::where('seller_profile_id', $profile->id)->sum('stock'),
        ];
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white" data-test="seller-store-heading">
                {{ $this->sellerProfile?->store_name }}
            </h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Welcome to your seller control center.</p>
        </div>

        <div>
            <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800">
                <span class="size-1.5 rounded-full bg-emerald-500"></span>
                Store Active and Approved
            </span>
        </div>
    </div>

    <!-- Product Catalog Quick Stats Card -->
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 pb-4 border-b border-zinc-100 dark:border-zinc-800">
            <div>
                <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Product Catalog & Inventory</h3>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Overview of your catalog submissions and current stock.</p>
            </div>
            <div class="flex items-center gap-2">
                <a href="{{ route('seller.products.index') }}" wire:navigate
                   class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                    View All Products
                </a>
                <a href="{{ route('seller.products.create') }}" wire:navigate
                   class="rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 transition shadow-2xs">
                    + Add Product
                </a>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 pt-4 text-xs">
            <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-3 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                <span class="text-zinc-500 dark:text-zinc-400 block">Total Catalog Items</span>
                <span class="text-xl font-bold text-zinc-900 dark:text-white mt-1 block">{{ $this->productStats['total'] }}</span>
            </div>

            <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-3 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                <span class="text-zinc-500 dark:text-zinc-400 block">Under QC Review</span>
                <span class="text-xl font-bold text-amber-600 dark:text-amber-400 mt-1 block">{{ $this->productStats['pending'] }}</span>
            </div>

            <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-3 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                <span class="text-zinc-500 dark:text-zinc-400 block">Live & Approved</span>
                <span class="text-xl font-bold text-emerald-600 dark:text-emerald-400 mt-1 block">{{ $this->productStats['approved'] }}</span>
            </div>

            <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-3 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                <span class="text-zinc-500 dark:text-zinc-400 block">Total Units In Stock</span>
                <span class="text-xl font-bold text-zinc-900 dark:text-white mt-1 block">{{ number_format($this->productStats['stock']) }}</span>
            </div>
        </div>
    </div>

    <!-- Store Profile Card -->
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-6 dark:border-zinc-800">
            <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Store Information</h3>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Your public store identity and business details.</p>
        </div>

        <div class="p-6">
            <dl class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Store Name</dt>
                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $this->sellerProfile?->store_name }}</dd>
                </div>

                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Store Slug</dt>
                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">/stores/{{ $this->sellerProfile?->store_slug }}</dd>
                </div>

                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Business Structure</dt>
                    <dd class="mt-1 text-sm font-semibold capitalize text-zinc-900 dark:text-white">{{ $this->sellerProfile?->business_type }}</dd>
                </div>

                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Support Email</dt>
                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $this->sellerProfile?->support_email }}</dd>
                </div>

                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Support Phone</dt>
                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $this->sellerProfile?->support_phone }}</dd>
                </div>

                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Approved On</dt>
                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">
                        {{ $this->sellerProfile?->approved_at?->format('M j, Y') ?? '—' }}
                    </dd>
                </div>
            </dl>
        </div>
    </div>

    <!-- Pickup Address Card -->
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="border-b border-zinc-200 p-6 dark:border-zinc-800">
            <h3 class="text-base font-semibold text-zinc-900 dark:text-white">Registered Pickup Address</h3>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Location where shipments are collected.</p>
        </div>
        <div class="p-6">
            <p class="text-sm text-zinc-800 dark:text-zinc-200">
                {{ $this->sellerProfile?->pickup_address_line1 }}
                @if ($this->sellerProfile?->pickup_address_line2)
                    , {{ $this->sellerProfile->pickup_address_line2 }}
                @endif
                <br>
                {{ $this->sellerProfile?->pickup_city }}, {{ $this->sellerProfile?->pickup_state }} - {{ $this->sellerProfile?->pickup_postal_code }}
                <br>
                {{ $this->sellerProfile?->pickup_country }}
            </p>

            <div class="mt-4 flex gap-3">
                <a href="{{ route('seller.profile') }}" wire:navigate class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                    Edit Store & Address Details
                </a>
                <a href="{{ route('seller.settings') }}" wire:navigate class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                    Manage Bank Details
                </a>
            </div>
        </div>
    </div>
</div>
