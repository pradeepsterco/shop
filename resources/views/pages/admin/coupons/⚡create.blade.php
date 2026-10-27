<?php

namespace App\Livewire\Admin\Coupons;

use App\Coupons\Enums\CouponFundedBy;
use App\Coupons\Enums\CouponStatus;
use App\Coupons\Enums\DiscountType;
use App\Coupons\Enums\ScopeRuleType;
use App\Coupons\Enums\ScopeType;
use App\Coupons\Models\Coupon;
use App\Coupons\Models\CouponScope;
use App\Services\AdminActivityLogger;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Create Coupon')] class extends Component {
    public string $code = '';

    public string $name = '';

    public string $description = '';

    public string $status = 'active';

    public string $discount_type = 'percentage';

    public string $discount_value = '';

    public string $max_discount = '';

    public string $min_order_amount = '0';

    public string $starts_at = '';

    public string $expires_at = '';

    public string $usage_limit_total = '';

    public string $usage_limit_per_user = '';

    public bool $first_order_only = false;

    public string $funded_by = 'platform';

    // Scopes (comma separated IDs for category, brand, seller, product)
    public string $include_category_ids = '';

    public string $exclude_category_ids = '';

    public string $include_brand_ids = '';

    public string $exclude_brand_ids = '';

    public string $include_seller_ids = '';

    public string $exclude_seller_ids = '';

    public string $include_product_ids = '';

    public string $exclude_product_ids = '';

    public function generateCode(): void
    {
        do {
            $code = strtoupper(Str::random(8));
        } while (Coupon::where('code', $code)->exists());

        $this->code = $code;
    }

    public function save(): void
    {
        $this->authorize('coupons.manage');

        // Rule 4: Platform-funded only in this phase
        if ($this->funded_by !== 'platform') {
            $this->addError('funded_by', 'Seller-funded coupons are not supported in this phase.');

            return;
        }

        $this->validate([
            'code' => ['required', 'string', 'max:32', 'unique:coupons,code'],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'in:draft,active,paused,expired'],
            'discount_type' => ['required', 'in:percentage,fixed'],
            'discount_value' => ['required', 'numeric', 'min:1'],
            'max_discount' => ['nullable', 'numeric', 'min:1'],
            'min_order_amount' => ['nullable', 'numeric', 'min:0'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date', 'after_or_equal:starts_at'],
            'usage_limit_total' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_user' => ['nullable', 'integer', 'min:1'],
            'first_order_only' => ['boolean'],
            'funded_by' => ['required', 'in:platform'],
        ]);

        $code = strtoupper(trim($this->code));

        // Convert values to paise / integer points
        $val = (float) $this->discount_value;
        $discountValue = ($this->discount_type === 'percentage')
            ? (int) round($val)
            : (int) round($val * 100);

        $maxDiscount = ($this->max_discount !== '') ? (int) round(((float) $this->max_discount) * 100) : null;
        $minOrderAmount = ($this->min_order_amount !== '') ? (int) round(((float) $this->min_order_amount) * 100) : 0;

        /** @var Coupon $coupon */
        $coupon = Coupon::create([
            'code' => $code,
            'name' => trim($this->name),
            'description' => trim($this->description) ?: null,
            'status' => CouponStatus::from($this->status),
            'discount_type' => DiscountType::from($this->discount_type),
            'discount_value' => $discountValue,
            'max_discount' => $maxDiscount,
            'min_order_amount' => $minOrderAmount,
            'starts_at' => $this->starts_at ? $this->starts_at : null,
            'expires_at' => $this->expires_at ? $this->expires_at : null,
            'usage_limit_total' => $this->usage_limit_total !== '' ? (int) $this->usage_limit_total : null,
            'usage_limit_per_user' => $this->usage_limit_per_user !== '' ? (int) $this->usage_limit_per_user : null,
            'first_order_only' => $this->first_order_only,
            'funded_by' => CouponFundedBy::Platform,
            'seller_id' => null,
        ]);

        // Save Scopes
        $this->saveScopeGroup($coupon->id, ScopeType::Category, ScopeRuleType::Include, $this->include_category_ids);
        $this->saveScopeGroup($coupon->id, ScopeType::Category, ScopeRuleType::Exclude, $this->exclude_category_ids);
        $this->saveScopeGroup($coupon->id, ScopeType::Brand, ScopeRuleType::Include, $this->include_brand_ids);
        $this->saveScopeGroup($coupon->id, ScopeType::Brand, ScopeRuleType::Exclude, $this->exclude_brand_ids);
        $this->saveScopeGroup($coupon->id, ScopeType::Seller, ScopeRuleType::Include, $this->include_seller_ids);
        $this->saveScopeGroup($coupon->id, ScopeType::Seller, ScopeRuleType::Exclude, $this->exclude_seller_ids);
        $this->saveScopeGroup($coupon->id, ScopeType::Product, ScopeRuleType::Include, $this->include_product_ids);
        $this->saveScopeGroup($coupon->id, ScopeType::Product, ScopeRuleType::Exclude, $this->exclude_product_ids);

        AdminActivityLogger::log('coupon.created', $coupon, [
            'code' => $coupon->code,
            'type' => $coupon->discount_type->value,
            'value' => $coupon->discount_value,
        ]);

        session()->flash('success', "Coupon '{$coupon->code}' created successfully!");
        $this->redirect(route('admin.coupons.index'), navigate: true);
    }

    private function saveScopeGroup(int $couponId, ScopeType $type, ScopeRuleType $rule, string $idCsv): void
    {
        $raw = array_filter(array_map('trim', explode(',', $idCsv)));
        foreach ($raw as $idStr) {
            if (is_numeric($idStr)) {
                CouponScope::create([
                    'coupon_id' => $couponId,
                    'scope_type' => $type,
                    'scope_id' => (int) $idStr,
                    'rule_type' => $rule,
                ]);
            }
        }
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
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white mt-1">Create New Coupon</h1>
        </div>
    </div>

    <form wire:submit.prevent="save" class="space-y-6">
        <!-- Basic Info Card -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 border-b border-zinc-100 dark:border-zinc-800 pb-3">
                Coupon Identification
            </h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Coupon Code *</label>
                    <div class="flex gap-2">
                        <input
                            type="text"
                            wire:model="code"
                            placeholder="e.g. SAVE20"
                            class="flex-1 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm uppercase tracking-wider text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white"
                        />
                        <button
                            type="button"
                            wire:click="generateCode"
                            class="rounded-xl border border-zinc-300 px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 cursor-pointer"
                        >
                            Generate
                        </button>
                    </div>
                    @error('code') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Coupon Name *</label>
                    <input
                        type="text"
                        wire:model="name"
                        placeholder="e.g. Summer Super Savings"
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white"
                    />
                    @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Description (Internal or Customer note)</label>
                <textarea
                    wire:model="description"
                    rows="2"
                    placeholder="Brief description of the promotion..."
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
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Funding Party</label>
                    <select
                        wire:model="funded_by"
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white"
                    >
                        <option value="platform">Platform Funded</option>
                        <option value="seller" disabled>Seller Funded (Unsupported in this phase)</option>
                    </select>
                    @error('funded_by') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>
        </div>

        <!-- Discount Rules Card -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 border-b border-zinc-100 dark:border-zinc-800 pb-3">
                Discount Configuration
            </h2>

            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Discount Type</label>
                    <select
                        wire:model.live="discount_type"
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white"
                    >
                        <option value="percentage">Percentage (%)</option>
                        <option value="fixed">Fixed Amount (₹)</option>
                    </select>
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">
                        {{ $discount_type === 'percentage' ? 'Percentage (e.g. 20 for 20%) *' : 'Amount in ₹ (e.g. 100) *' }}
                    </label>
                    <input
                        type="number"
                        wire:model="discount_value"
                        min="1"
                        step="any"
                        placeholder="e.g. 20"
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white"
                    />
                    @error('discount_value') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                @if ($discount_type === 'percentage')
                    <div>
                        <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Maximum Discount Cap (₹)</label>
                        <input
                            type="number"
                            wire:model="max_discount"
                            min="1"
                            placeholder="Optional cap in ₹"
                            class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white"
                        />
                        @error('max_discount') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                    </div>
                @endif
            </div>

            <div>
                <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Minimum Order Spend (₹)</label>
                <input
                    type="number"
                    wire:model="min_order_amount"
                    min="0"
                    placeholder="0 for no minimum"
                    class="w-full sm:w-1/2 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white"
                />
                <p class="text-[11px] text-zinc-500 mt-1">Minimum cart subtotal of eligible items required to activate coupon.</p>
                @error('min_order_amount') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
            </div>
        </div>

        <!-- Limits & Dates Card -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 border-b border-zinc-100 dark:border-zinc-800 pb-3">
                Validity & Limits
            </h2>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Starts At (UTC)</label>
                    <input
                        type="datetime-local"
                        wire:model="starts_at"
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white"
                    />
                    @error('starts_at') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Expires At (UTC)</label>
                    <input
                        type="datetime-local"
                        wire:model="expires_at"
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white"
                    />
                    @error('expires_at') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Global Usage Limit</label>
                    <input
                        type="number"
                        wire:model="usage_limit_total"
                        min="1"
                        placeholder="Leave blank for unlimited"
                        class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm text-zinc-900 focus:border-zinc-900 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white"
                    />
                    @error('usage_limit_total') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Usage Limit Per User</label>
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

            <div class="pt-2">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input
                        type="checkbox"
                        wire:model="first_order_only"
                        class="h-4 w-4 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-900 dark:border-zinc-700 dark:bg-zinc-800"
                    />
                    <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">First Order Only (Valid only for customers with no previous completed orders)</span>
                </label>
            </div>
        </div>

        <!-- Scopes & Restrictions Card -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-5">
            <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 border-b border-zinc-100 dark:border-zinc-800 pb-3">
                Scope Restrictions (Optional)
            </h2>
            <p class="text-xs text-zinc-500">Provide comma-separated integer IDs to limit or exclude categories, brands, sellers, or products.</p>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Include Category IDs</label>
                    <input type="text" wire:model="include_category_ids" placeholder="e.g. 1, 4, 12" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs font-mono dark:border-zinc-800 dark:bg-zinc-800 dark:text-white" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Exclude Category IDs</label>
                    <input type="text" wire:model="exclude_category_ids" placeholder="e.g. 2, 5" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs font-mono dark:border-zinc-800 dark:bg-zinc-800 dark:text-white" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Include Brand IDs</label>
                    <input type="text" wire:model="include_brand_ids" placeholder="e.g. 3, 8" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs font-mono dark:border-zinc-800 dark:bg-zinc-800 dark:text-white" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Exclude Brand IDs</label>
                    <input type="text" wire:model="exclude_brand_ids" placeholder="e.g. 9" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs font-mono dark:border-zinc-800 dark:bg-zinc-800 dark:text-white" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Include Seller Profile IDs</label>
                    <input type="text" wire:model="include_seller_ids" placeholder="e.g. 2" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs font-mono dark:border-zinc-800 dark:bg-zinc-800 dark:text-white" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Exclude Seller Profile IDs</label>
                    <input type="text" wire:model="exclude_seller_ids" placeholder="e.g. 7" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs font-mono dark:border-zinc-800 dark:bg-zinc-800 dark:text-white" />
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Include Product IDs</label>
                    <input type="text" wire:model="include_product_ids" placeholder="e.g. 101, 102" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs font-mono dark:border-zinc-800 dark:bg-zinc-800 dark:text-white" />
                </div>
                <div>
                    <label class="block text-xs font-semibold text-zinc-700 dark:text-zinc-300 mb-1">Exclude Product IDs</label>
                    <input type="text" wire:model="exclude_product_ids" placeholder="e.g. 204" class="w-full rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs font-mono dark:border-zinc-800 dark:bg-zinc-800 dark:text-white" />
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
                Cancel
            </a>
            <button
                type="submit"
                wire:loading.attr="disabled"
                class="rounded-xl bg-zinc-900 px-6 py-2.5 text-sm font-bold text-white shadow-xs hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition cursor-pointer"
            >
                Save Coupon
            </button>
        </div>
    </form>
</div>

