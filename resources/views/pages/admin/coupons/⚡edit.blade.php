<?php

namespace App\Livewire\Admin\Coupons;

use App\Coupons\Enums\CouponStatus;
use App\Coupons\Models\Coupon;
use App\Coupons\Models\CouponRedemption;
use App\Orders\Models\Order;
use App\Orders\Services\CouponOrderReportingService;
use App\Services\AdminActivityLogger;
use Illuminate\Pagination\LengthAwarePaginator;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Edit Coupon')] class extends Component {
    use WithPagination;

    public Coupon $coupon;

    public bool $isLocked = false;

    // Editable fields
    public string $name = '';

    public string $description = '';

    public string $status = 'active';

    public string $expires_at = '';

    public string $usage_limit_total = '';

    public string $usage_limit_per_user = '';

    public string $funded_by = 'platform';

    public function mount(Coupon $coupon): void
    {
        $this->authorize('coupons.view');
        $this->coupon = $coupon->load('scopes');

        $this->isLocked = $coupon->hasRedemptions();

        $this->name = $coupon->name;
        $this->description = $coupon->description ?? '';
        $this->status = $coupon->status->value;
        $this->funded_by = $coupon->funded_by->value;
        $this->expires_at = $coupon->expires_at ? $coupon->expires_at->format('Y-m-d\TH:i') : '';
        $this->usage_limit_total = $coupon->usage_limit_total ? (string) $coupon->usage_limit_total : '';
        $this->usage_limit_per_user = $coupon->usage_limit_per_user ? (string) $coupon->usage_limit_per_user : '';
    }

    #[Computed]
    public function redemptions(): LengthAwarePaginator
    {
        return CouponRedemption::query()
            ->where('coupon_id', $this->coupon->id)
            ->latest('id')
            ->paginate(10);
    }

    #[Computed]
    public function totalDiscountGiven(): int
    {
        return app(CouponOrderReportingService::class)->getTotalDiscountGiven($this->coupon->id);
    }

    /**
     * @return array<int, array{label: string, badge_class: string, discount_given: int}>
     */
    #[Computed]
    public function redemptionStatuses(): array
    {
        $reporting = app(CouponOrderReportingService::class);
        /** @var array<string> $orderNumbers */
        $orderNumbers = $this->redemptions->pluck('order_reference')->unique()->all();
        $orders = Order::with('sellerOrders')->whereIn('order_number', $orderNumbers)->get()->keyBy('order_number');

        $map = [];
        foreach ($this->redemptions as $redemption) {
            $order = $orders->get($redemption->order_reference);
            $map[$redemption->id] = $reporting->getRedemptionDisplayStatus($redemption, $order);
        }

        return $map;
    }

    public function update(): void
    {
        $this->authorize('coupons.manage');

        if ($this->funded_by !== 'platform') {
            $this->addError('funded_by', 'Seller-funded coupons are not supported in this phase.');

            return;
        }

        $rules = [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'in:draft,active,paused,expired'],
            'expires_at' => ['nullable', 'date'],
            'usage_limit_total' => ['nullable', 'integer', 'min:'.($this->coupon->usage_count ?: 1)],
            'usage_limit_per_user' => ['nullable', 'integer', 'min:1'],
        ];

        $this->validate($rules);

        $oldStatus = $this->coupon->status;
        $newStatus = CouponStatus::from($this->status);

        $this->coupon->update([
            'name' => trim($this->name),
            'description' => trim($this->description) ?: null,
            'status' => $newStatus,
            'expires_at' => $this->expires_at ? $this->expires_at : null,
            'usage_limit_total' => $this->usage_limit_total !== '' ? (int) $this->usage_limit_total : null,
            'usage_limit_per_user' => $this->usage_limit_per_user !== '' ? (int) $this->usage_limit_per_user : null,
        ]);

        AdminActivityLogger::log('coupon.updated', $this->coupon, [
            'code' => $this->coupon->code,
            'status' => $newStatus->value,
            'status_changed' => $oldStatus !== $newStatus,
        ]);

        session()->flash('success', "Coupon '{$this->coupon->code}' updated successfully.");
    }
}; ?>

<div class="mx-auto max-w-4xl space-y-6">
    <!-- Header -->
    <div class="flex items-center justify-between">
        <div>
            <div class="flex items-center gap-2">
                <a href="{{ route('admin.coupons.index') }}" wire:navigate class="text-xs font-semibold text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200">
                    &larr; Coupons
                </a>
            </div>
            <div class="flex items-center gap-3 mt-1">
                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                    Coupon: <span class="font-mono text-zinc-700 dark:text-zinc-300">{{ $coupon->code }}</span>
                </h1>
                <span class="inline-flex items-center rounded-full border px-2.5 py-0.5 text-xs font-semibold {{ $coupon->status->value === 'active' ? 'bg-emerald-50 text-emerald-700 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800' : 'bg-zinc-100 text-zinc-700 border-zinc-200 dark:bg-zinc-800 dark:text-zinc-300' }}">
                    {{ $coupon->status->label() }}
                </span>
            </div>
        </div>
    </div>

    <!-- Summary Stat Cards -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">Total Discount Given</span>
            <div class="text-xl font-bold text-zinc-900 dark:text-white mt-1">
                ₹{{ number_format($this->totalDiscountGiven / 100) }}
            </div>
            <p class="text-[11px] text-zinc-400 mt-0.5">Excludes cancelled & RTO orders</p>
        </div>
        <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">Total Redemptions</span>
            <div class="text-xl font-bold text-zinc-900 dark:text-white mt-1">
                {{ $this->redemptions->total() }}
            </div>
            <p class="text-[11px] text-zinc-400 mt-0.5">All customer claims recorded</p>
        </div>
        <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <span class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">Active Usage Count</span>
            <div class="text-xl font-bold text-zinc-900 dark:text-white mt-1">
                {{ $coupon->usage_count }}
                @if ($coupon->usage_limit_total)
                    <span class="text-zinc-400 text-sm font-normal">/ {{ $coupon->usage_limit_total }}</span>
                @endif
            </div>
            <p class="text-[11px] text-zinc-400 mt-0.5">Counts towards global limit</p>
        </div>
    </div>

    @if (session()->has('success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
            {{ session('success') }}
        </div>
    @endif

    @if ($isLocked)
        <div class="rounded-xl border border-amber-200 bg-amber-50/70 p-4 text-xs text-amber-900 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-300 flex items-start gap-3">
            <svg class="h-5 w-5 text-amber-600 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
            </svg>
            <div>
                <p class="font-bold">Historical Audit Lock Active</p>
                <p class="mt-0.5 text-amber-800 dark:text-amber-400">This coupon has been redeemed by customers. Discount values, codes, funding types, and scope rules are locked to protect order history integrity. You may still update name, status, expiry, and limits.</p>
            </div>
        </div>
    @endif

    <form wire:submit.prevent="update" class="space-y-6">
        <!-- Identification & Status Card -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 border-b border-zinc-100 dark:border-zinc-800 pb-3">
                General Settings
            </h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Coupon Code</label>
                    <input
                        type="text"
                        value="{{ $coupon->code }}"
                        disabled
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-100 px-3 py-2 text-sm font-mono uppercase text-zinc-500 cursor-not-allowed dark:border-zinc-800 dark:bg-zinc-800/60 dark:text-zinc-400"
                    />
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Coupon Name *</label>
                    <input
                        type="text"
                        wire:model="name"
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white"
                    />
                    @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Description</label>
                <textarea
                    wire:model="description"
                    rows="2"
                    class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white"
                ></textarea>
                @error('description') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Status</label>
                    <select
                        wire:model="status"
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white"
                    >
                        <option value="active">Active (Redeemable)</option>
                        <option value="paused">Paused</option>
                        <option value="draft">Draft</option>
                        <option value="expired">Expired</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Funding Party</label>
                    <input
                        type="text"
                        value="Platform Funded"
                        disabled
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-100 px-3 py-2 text-sm text-zinc-500 cursor-not-allowed dark:border-zinc-800 dark:bg-zinc-800/60 dark:text-zinc-400"
                    />
                    @error('funded_by') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <!-- Locked Core Terms Card -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
            <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 border-b border-zinc-100 dark:border-zinc-800 pb-3 flex items-center justify-between">
                <span>Discount Structure</span>
                @if ($isLocked)
                    <span class="text-[10px] text-amber-600 dark:text-amber-400 font-mono font-bold uppercase tracking-wider">Locked</span>
                @endif
            </h2>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 text-xs">
                <div class="p-3 bg-zinc-50 dark:bg-zinc-800/40 rounded-xl border border-zinc-100 dark:border-zinc-800">
                    <span class="text-zinc-500">Discount Type</span>
                    <p class="text-sm font-bold text-zinc-900 dark:text-white mt-1">{{ $coupon->discount_type->label() }}</p>
                </div>
                <div class="p-3 bg-zinc-50 dark:bg-zinc-800/40 rounded-xl border border-zinc-100 dark:border-zinc-800">
                    <span class="text-zinc-500">Discount Value</span>
                    <p class="text-sm font-bold text-zinc-900 dark:text-white mt-1">
                        {{ $coupon->discount_type->value === 'percentage' ? "{$coupon->discount_value}%" : '₹'.number_format($coupon->discount_value / 100) }}
                    </p>
                </div>
                <div class="p-3 bg-zinc-50 dark:bg-zinc-800/40 rounded-xl border border-zinc-100 dark:border-zinc-800">
                    <span class="text-zinc-500">Max Discount Cap</span>
                    <p class="text-sm font-bold text-zinc-900 dark:text-white mt-1">
                        {{ $coupon->max_discount ? '₹'.number_format($coupon->max_discount / 100) : 'None' }}
                    </p>
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-xs pt-2">
                <div class="p-3 bg-zinc-50 dark:bg-zinc-800/40 rounded-xl border border-zinc-100 dark:border-zinc-800">
                    <span class="text-zinc-500">Min Order Requirement</span>
                    <p class="text-sm font-bold text-zinc-900 dark:text-white mt-1">
                        {{ $coupon->min_order_amount > 0 ? '₹'.number_format($coupon->min_order_amount / 100) : '₹0 (No minimum)' }}
                    </p>
                </div>
                <div class="p-3 bg-zinc-50 dark:bg-zinc-800/40 rounded-xl border border-zinc-100 dark:border-zinc-800">
                    <span class="text-zinc-500">First Order Only</span>
                    <p class="text-sm font-bold text-zinc-900 dark:text-white mt-1">
                        {{ $coupon->first_order_only ? 'Yes' : 'No' }}
                    </p>
                </div>
            </div>

            @if ($coupon->scopes->isNotEmpty())
                <div class="pt-2">
                    <span class="text-xs font-semibold text-zinc-500">Scope Rules:</span>
                    <div class="mt-2 flex flex-wrap gap-2">
                        @foreach ($coupon->scopes as $scope)
                            <span class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-mono border {{ $scope->rule_type->value === 'include' ? 'bg-emerald-50 text-emerald-800 border-emerald-200 dark:bg-emerald-950/40 dark:text-emerald-300 dark:border-emerald-800' : 'bg-red-50 text-red-800 border-red-200 dark:bg-red-950/40 dark:text-red-300 dark:border-red-800' }}">
                                <span class="uppercase font-bold">{{ $scope->rule_type->value }}</span>
                                <span>{{ $scope->scope_type->value }}:{{ $scope->scope_id }}</span>
                            </span>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>

        <!-- Limits & Expiry Card -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 border-b border-zinc-100 dark:border-zinc-800 pb-3">
                Limits & Expiry
            </h2>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Expires At (UTC)</label>
                    <input
                        type="datetime-local"
                        wire:model="expires_at"
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white"
                    />
                    @error('expires_at') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Global Limit (Current: {{ $coupon->usage_count }})</label>
                    <input
                        type="number"
                        wire:model="usage_limit_total"
                        min="{{ $coupon->usage_count ?: 1 }}"
                        placeholder="Leave blank for unlimited"
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white"
                    />
                    @error('usage_limit_total') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Per-User Limit</label>
                    <input
                        type="number"
                        wire:model="usage_limit_per_user"
                        min="1"
                        placeholder="Leave blank for unlimited"
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white"
                    />
                    @error('usage_limit_per_user') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="flex items-center justify-end gap-3">
            <a
                href="{{ route('admin.coupons.index') }}"
                wire:navigate
                class="rounded-xl border border-zinc-300 px-4 py-2.5 text-sm font-semibold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition"
            >
                Back to List
            </a>
            @can('coupons.manage')
                <button
                    type="submit"
                    wire:loading.attr="disabled"
                    class="rounded-xl bg-zinc-900 px-6 py-2.5 text-sm font-bold text-white shadow-xs hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition cursor-pointer"
                >
                    Save Changes
                </button>
            @endcan
        </div>
    </form>

    <!-- Redemptions List Table -->
    <div class="rounded-2xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
        <div class="p-6 border-b border-zinc-100 dark:border-zinc-800">
            <h2 class="text-sm font-bold text-zinc-900 dark:text-white uppercase tracking-wider">
                Redemption History ({{ $this->redemptions->total() }} total)
            </h2>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50/75 text-xs font-bold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/40 dark:text-zinc-400">
                    <tr>
                        <th class="px-6 py-3.5">Order Ref</th>
                        <th class="px-6 py-3.5">Customer</th>
                        <th class="px-6 py-3.5">Coupon Value</th>
                        <th class="px-6 py-3.5">Discount Given</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5">Released (₹)</th>
                        <th class="px-6 py-3.5">Date (IST)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($this->redemptions as $redemption)
                        @php
                            $statusInfo = $this->redemptionStatuses[$redemption->id] ?? null;
                            $displayLabel = $statusInfo['label'] ?? $redemption->status->label();
                            $badgeClass = $statusInfo['badge_class'] ?? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300';
                            $discountGiven = $statusInfo['discount_given'] ?? max(0, (int) $redemption->discount_amount - (int) $redemption->released_amount);
                        @endphp
                        <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/25 transition">
                            <td class="px-6 py-3 font-mono font-bold text-zinc-900 dark:text-white">
                                {{ $redemption->order_reference }}
                            </td>
                            <td class="px-6 py-3 text-zinc-600 dark:text-zinc-400 font-mono text-xs">
                                User #{{ $redemption->customer_reference }}
                            </td>
                            <td class="px-6 py-3 font-bold text-zinc-900 dark:text-white">
                                ₹{{ number_format($redemption->discount_amount / 100) }}
                            </td>
                            <td class="px-6 py-3 font-bold {{ $discountGiven > 0 ? 'text-emerald-600 dark:text-emerald-400' : 'text-zinc-400' }}">
                                ₹{{ number_format($discountGiven / 100) }}
                            </td>
                            <td class="px-6 py-3">
                                <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $badgeClass }}">
                                    {{ $displayLabel }}
                                </span>
                            </td>
                            <td class="px-6 py-3 text-xs font-mono text-zinc-600 dark:text-zinc-400">
                                {{ $redemption->released_amount > 0 ? '₹'.number_format($redemption->released_amount / 100) : '—' }}
                            </td>
                            <td class="px-6 py-3 text-xs text-zinc-500">
                                @datetime($redemption->redeemed_at)
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-8 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                No redemptions recorded for this coupon yet.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->redemptions->hasPages())
            <div class="border-t border-zinc-200 px-6 py-4 dark:border-zinc-800">
                {{ $this->redemptions->links() }}
            </div>
        @endif
    </div>
</div>

