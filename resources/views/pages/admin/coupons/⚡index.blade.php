<?php

namespace App\Livewire\Admin\Coupons;

use App\Coupons\Enums\CouponStatus;
use App\Coupons\Models\Coupon;
use App\Coupons\Models\CouponScope;
use App\Orders\Services\CouponOrderReportingService;
use App\Services\AdminActivityLogger;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Coupons & Promotions')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $statusFilter = 'all';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function coupons(): LengthAwarePaginator
    {
        $this->authorize('coupons.view');

        return Coupon::query()
            ->withCount('redemptions')
            ->when($this->search !== '', function ($q) {
                $term = '%'.trim($this->search).'%';
                $q->where(function ($sub) use ($term) {
                    $sub->where('code', 'like', $term)
                        ->orWhere('name', 'like', $term);
                });
            })
            ->when($this->statusFilter !== 'all', function ($q) {
                $q->where('status', $this->statusFilter);
            })
            ->latest('id')
            ->paginate(15);
    }

    /**
     * @return array<int, int>
     */
    #[Computed]
    public function discountsGiven(): array
    {
        /** @var array<int> $ids */
        $ids = $this->coupons->pluck('id')->all();

        return app(CouponOrderReportingService::class)->getTotalDiscountsGivenForCoupons($ids);
    }

    public function toggleStatus(int $couponId): void
    {
        $this->authorize('coupons.manage');

        $coupon = Coupon::findOrFail($couponId);
        $oldStatus = $coupon->status;

        $newStatus = match ($coupon->status) {
            CouponStatus::Active => CouponStatus::Paused,
            CouponStatus::Paused => CouponStatus::Active,
            CouponStatus::Draft => CouponStatus::Active,
            CouponStatus::Expired => CouponStatus::Active,
        };

        $coupon->update(['status' => $newStatus]);

        AdminActivityLogger::log('coupon.status_changed', $coupon, [
            'from' => $oldStatus->value,
            'to' => $newStatus->value,
            'code' => $coupon->code,
        ]);

        session()->flash('success', "Coupon '{$coupon->code}' status updated to {$newStatus->label()}.");
    }

    public function duplicate(int $couponId): void
    {
        $this->authorize('coupons.manage');

        $source = Coupon::with('scopes')->findOrFail($couponId);

        // Generate unique code
        do {
            $newCode = strtoupper(Str::random(8));
        } while (Coupon::where('code', $newCode)->exists());

        $newCoupon = Coupon::create([
            'code' => $newCode,
            'name' => "Copy of {$source->name}",
            'description' => $source->description,
            'status' => CouponStatus::Draft,
            'discount_type' => $source->discount_type,
            'discount_value' => $source->discount_value,
            'max_discount' => $source->max_discount,
            'min_order_amount' => $source->min_order_amount,
            'starts_at' => null,
            'expires_at' => null,
            'usage_limit_total' => $source->usage_limit_total,
            'usage_limit_per_user' => $source->usage_limit_per_user,
            'first_order_only' => $source->first_order_only,
            'funded_by' => $source->funded_by,
            'seller_id' => $source->seller_id,
        ]);

        // Duplicate scopes
        foreach ($source->scopes as $scope) {
            CouponScope::create([
                'coupon_id' => $newCoupon->id,
                'scope_type' => $scope->scope_type,
                'scope_id' => $scope->scope_id,
                'rule_type' => $scope->rule_type,
            ]);
        }

        AdminActivityLogger::log('coupon.duplicated', $newCoupon, [
            'source_id' => $source->id,
            'source_code' => $source->code,
            'new_code' => $newCoupon->code,
        ]);

        session()->flash('success', "Coupon duplicated as draft with code '{$newCoupon->code}'.");
        $this->redirect(route('admin.coupons.edit', $newCoupon), navigate: true);
    }

    public function deleteCoupon(int $couponId): void
    {
        $this->authorize('coupons.manage');

        $coupon = Coupon::withCount('redemptions')->findOrFail($couponId);

        if ($coupon->redemptions_count > 0) {
            session()->flash('error', "Coupon '{$coupon->code}' has been redeemed and cannot be deleted. You may pause it instead.");

            return;
        }

        $code = $coupon->code;
        $coupon->scopes()->delete();
        $coupon->delete();

        AdminActivityLogger::log('coupon.deleted', $coupon, [
            'code' => $code,
        ]);

        session()->flash('success', "Coupon '{$code}' deleted successfully.");
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Coupons & Promotions</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400 mt-1">Manage discount vouchers, rules, and promotional campaigns.</p>
        </div>
        @can('coupons.manage')
            <a
                href="{{ route('admin.coupons.create') }}"
                wire:navigate
                class="inline-flex items-center justify-center gap-2 rounded-xl bg-zinc-900 px-4 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition"
            >
                <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" />
                </svg>
                <span>Create Coupon</span>
            </a>
        @endcan
    </div>

    <!-- Flash message -->
    @if (session()->has('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
            {{ session('success') }}
        </div>
    @endif
    @if (session()->has('error'))
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700 dark:border-red-800 dark:bg-red-950/40 dark:text-red-300">
            {{ session('error') }}
        </div>
    @endif

    <!-- Filters & Search -->
    <div class="flex flex-col sm:flex-row gap-3 items-center justify-between rounded-2xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="relative w-full sm:w-80">
            <input
                type="text"
                wire:model.live.debounce.300ms="search"
                placeholder="Search by code or title..."
                class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3.5 py-2 pl-9 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500 dark:focus:border-white"
            />
            <svg class="absolute left-3 top-2.5 h-4 w-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
            </svg>
        </div>

        <div class="flex items-center gap-2 w-full sm:w-auto">
            <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">Status:</span>
            <select
                wire:model.live="statusFilter"
                class="rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs font-medium text-zinc-800 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-zinc-200"
            >
                <option value="all">All Statuses</option>
                <option value="active">Active</option>
                <option value="paused">Paused</option>
                <option value="draft">Draft</option>
                <option value="expired">Expired</option>
            </select>
        </div>
    </div>

    <!-- Table -->
    <div class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50/75 text-xs font-bold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/40 dark:text-zinc-400">
                    <tr>
                        <th class="px-6 py-3.5">Code / Name</th>
                        <th class="px-6 py-3.5">Discount</th>
                        <th class="px-6 py-3.5">Usage / Limit</th>
                        <th class="px-6 py-3.5">Total Discount Given</th>
                        <th class="px-6 py-3.5">Validity (IST)</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($this->coupons as $coupon)
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/25 transition">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-2">
                                    <span class="font-mono text-sm font-bold text-zinc-900 dark:text-white uppercase tracking-wider bg-zinc-100 dark:bg-zinc-800 px-2.5 py-1 rounded-md">
                                        {{ $coupon->code }}
                                    </span>
                                    @if ($coupon->first_order_only)
                                        <span class="rounded-full bg-blue-50 px-2 py-0.5 text-[10px] font-semibold text-blue-700 dark:bg-blue-950/40 dark:text-blue-300 border border-blue-200 dark:border-blue-900">
                                            1st Order
                                        </span>
                                    @endif
                                </div>
                                <p class="text-xs text-zinc-600 dark:text-zinc-400 mt-1 font-medium">{{ $coupon->name }}</p>
                            </td>

                            <td class="px-6 py-4">
                                @if ($coupon->discount_type->value === 'percentage')
                                    <span class="font-bold text-zinc-900 dark:text-white">{{ $coupon->discount_value }}% OFF</span>
                                    @if ($coupon->max_discount)
                                        <div class="text-[11px] text-zinc-500">Up to ₹{{ number_format($coupon->max_discount / 100) }}</div>
                                    @endif
                                @else
                                    <span class="font-bold text-zinc-900 dark:text-white">₹{{ number_format($coupon->discount_value / 100) }} OFF</span>
                                @endif
                                @if ($coupon->min_order_amount > 0)
                                    <div class="text-[11px] text-zinc-500">Min: ₹{{ number_format($coupon->min_order_amount / 100) }}</div>
                                @endif
                            </td>

                            <td class="px-6 py-4">
                                <div class="font-semibold text-zinc-900 dark:text-white">
                                    {{ $coupon->redemptions_count }}
                                    @if ($coupon->usage_limit_total)
                                        <span class="text-zinc-400 font-normal">/ {{ $coupon->usage_limit_total }}</span>
                                    @else
                                        <span class="text-zinc-400 font-normal">/ &infin;</span>
                                    @endif
                                </div>
                                @if ($coupon->usage_limit_per_user)
                                    <div class="text-[11px] text-zinc-500">{{ $coupon->usage_limit_per_user }} per user</div>
                                @endif
                            </td>

                            <td class="px-6 py-4">
                                <span class="font-bold text-zinc-900 dark:text-white">
                                    ₹{{ number_format(($this->discountsGiven[$coupon->id] ?? 0) / 100) }}
                                </span>
                                <div class="text-[10px] text-zinc-400">Excl. cancelled/RTO</div>
                            </td>

                            <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-400">
                                @if ($coupon->starts_at)
                                    <div>From: @datetime($coupon->starts_at)</div>
                                @endif
                                @if ($coupon->expires_at)
                                    <div>To: @datetime($coupon->expires_at)</div>
                                @elseif (! $coupon->starts_at)
                                    <span class="text-zinc-400">Always valid</span>
                                @endif
                            </td>

                            <td class="px-6 py-4">
                                @php
                                    $statusClasses = match ($coupon->status->value) {
                                        'active' => 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300 border-emerald-200 dark:border-emerald-800',
                                        'paused' => 'bg-amber-50 text-amber-700 dark:bg-amber-950/40 dark:text-amber-300 border-amber-200 dark:border-amber-800',
                                        'draft' => 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 border-zinc-200 dark:border-zinc-700',
                                        'expired' => 'bg-red-50 text-red-700 dark:bg-red-950/40 dark:text-red-300 border-red-200 dark:border-red-800',
                                    };
                                @endphp
                                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $statusClasses }}">
                                    {{ $coupon->status->label() }}
                                </span>
                            </td>

                            <td class="px-6 py-4 text-right">
                                <div class="inline-flex items-center gap-2">
                                    @can('coupons.manage')
                                        <button
                                            type="button"
                                            wire:click="toggleStatus({{ $coupon->id }})"
                                            wire:loading.attr="disabled"
                                            class="text-xs font-semibold text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white cursor-pointer"
                                        >
                                            {{ $coupon->status->value === 'active' ? 'Pause' : 'Activate' }}
                                        </button>

                                        <button
                                            type="button"
                                            wire:click="duplicate({{ $coupon->id }})"
                                            wire:loading.attr="disabled"
                                            title="Duplicate coupon"
                                            class="text-xs font-semibold text-blue-600 hover:text-blue-700 dark:text-blue-400 cursor-pointer"
                                        >
                                            Duplicate
                                        </button>

                                        <a
                                            href="{{ route('admin.coupons.edit', $coupon) }}"
                                            wire:navigate
                                            class="text-xs font-semibold text-zinc-900 hover:underline dark:text-white"
                                        >
                                            Edit
                                        </a>

                                        @if ($coupon->redemptions_count === 0)
                                            <button
                                                type="button"
                                                wire:click="deleteCoupon({{ $coupon->id }})"
                                                wire:confirm="Are you sure you want to delete this coupon? This action cannot be undone."
                                                wire:loading.attr="disabled"
                                                class="text-xs font-semibold text-red-600 hover:text-red-700 dark:text-red-400 cursor-pointer"
                                            >
                                                Delete
                                            </button>
                                        @endif
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                No coupons found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->coupons->hasPages())
            <div class="border-t border-zinc-200 px-6 py-4 dark:border-zinc-800">
                {{ $this->coupons->links() }}
            </div>
        @endif
    </div>
</div>

