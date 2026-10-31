<?php

use App\Enums\SellerStatus;
use App\Mail\SellerApplicationApproved;
use App\Mail\SellerApplicationRejected;
use App\Models\Admin;
use App\Models\SellerProfile;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\OrderItem;
use App\Orders\Models\SellerOrder;
use App\Services\AdminActivityLogger;
use App\Support\AdminPermissions;
use App\Support\DateFormatter;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Review Seller Application')] class extends Component
{
    use WithPagination;

    public SellerProfile $sellerProfile;

    public string $rejection_reason = '';

    public bool $showRejectForm = false;

    #[Url]
    public string $tab = 'overview';

    #[Url]
    public string $ordersDatePreset = 'all';

    #[Url]
    public ?string $ordersDateFrom = null;

    #[Url]
    public ?string $ordersDateTo = null;

    public function mount(SellerProfile $sellerProfile): void
    {
        $this->sellerProfile = $sellerProfile->load(['user', 'documents', 'reviewer']);

        if ($this->tab === 'orders' && ! $this->canViewOrders()) {
            abort(403);
        }
    }

    public function updatedTab(string $value): void
    {
        if ($value === 'orders' && ! $this->canViewOrders()) {
            abort(403);
        }
        $this->resetPage('seller-orders-page');
    }

    public function updatedOrdersDatePreset(): void
    {
        $this->resetPage('seller-orders-page');
    }

    public function updatedOrdersDateFrom(): void
    {
        $this->resetPage('seller-orders-page');
    }

    public function updatedOrdersDateTo(): void
    {
        $this->resetPage('seller-orders-page');
    }

    public function canViewKyc(): bool
    {
        return Auth::guard('admin')->user()?->can(AdminPermissions::SELLERS_KYC) ?? false;
    }

    public function canViewOrders(): bool
    {
        return Auth::guard('admin')->user()?->can(AdminPermissions::ORDERS_VIEW) ?? false;
    }

    public function canViewCustomers(): bool
    {
        return Auth::guard('admin')->user()?->can(AdminPermissions::CUSTOMERS_VIEW) ?? false;
    }

    public function maskKycField(?string $value): string
    {
        if ($this->sellerProfile->isAnonymized()) {
            return '—';
        }

        if (blank($value)) {
            return 'None';
        }

        if ($this->canViewKyc()) {
            return $value;
        }

        return strlen($value) > 4 ? \Illuminate\Support\Str::mask($value, '•', 0, -4) : str_repeat('•', strlen($value));
    }

    /**
     * @param  \Illuminate\Database\Query\Builder|Builder  $q
     */
    protected function applySellerDateRange($q, string $prefix = ''): void
    {
        $column = $prefix.'placed_at';
        if ($this->ordersDatePreset === 'today') {
            [$start, $end] = DateFormatter::todayRange();
            $q->whereBetween($column, [$start, $end]);
        } elseif ($this->ordersDatePreset === '7_days') {
            [$start, $end] = DateFormatter::lastDaysRange(7);
            $q->whereBetween($column, [$start, $end]);
        } elseif ($this->ordersDatePreset === '30_days') {
            [$start, $end] = DateFormatter::lastDaysRange(30);
            $q->whereBetween($column, [$start, $end]);
        } elseif ($this->ordersDatePreset === 'custom') {
            [$start, $end] = DateFormatter::parseCustomRange($this->ordersDateFrom, $this->ordersDateTo);
            if ($start && $end) {
                $q->whereBetween($column, [$start, $end]);
            } elseif ($start) {
                $q->where($column, '>=', $start);
            } elseif ($end) {
                $q->where($column, '<=', $end);
            }
        }
    }

    #[Computed]
    public function sellerOrdersSummary(): array
    {
        if (! $this->canViewOrders()) {
            return [
                'total_orders' => 0,
                'gross_value' => 0,
                'cancelled_value' => 0,
                'net_value' => 0,
                'items_sold' => 0,
            ];
        }

        $base = DB::table('seller_orders')
            ->where('seller_profile_id', $this->sellerProfile->id);
        $this->applySellerDateRange($base);

        $totals = (clone $base)->selectRaw('
            COUNT(*) as total_orders,
            COALESCE(SUM(grand_total), 0) as gross_value,
            COALESCE(SUM(CASE WHEN status = "cancelled" THEN grand_total ELSE 0 END), 0) as cancelled_value
        ')->first();

        $grossValue = (int) ($totals->gross_value ?? 0);
        $cancelledValue = (int) ($totals->cancelled_value ?? 0);
        $netValue = max(0, $grossValue - $cancelledValue);
        $totalOrders = (int) ($totals->total_orders ?? 0);

        $itemsQuery = DB::table('order_items')
            ->join('seller_orders', 'order_items.seller_order_id', '=', 'seller_orders.id')
            ->where('seller_orders.seller_profile_id', $this->sellerProfile->id)
            ->where('seller_orders.status', '!=', 'cancelled');
        $this->applySellerDateRange($itemsQuery, 'seller_orders.');
        $itemsSold = (int) $itemsQuery->sum('order_items.qty');

        return [
            'total_orders' => $totalOrders,
            'gross_value' => $grossValue,
            'cancelled_value' => $cancelledValue,
            'net_value' => $netValue,
            'items_sold' => $itemsSold,
        ];
    }

    #[Computed]
    public function sellerTopProducts()
    {
        if (! $this->canViewOrders()) {
            return collect();
        }

        $query = DB::table('order_items')
            ->join('seller_orders', 'order_items.seller_order_id', '=', 'seller_orders.id')
            ->where('seller_orders.seller_profile_id', $this->sellerProfile->id)
            ->where('seller_orders.status', '!=', 'cancelled');
        $this->applySellerDateRange($query, 'seller_orders.');

        return $query
            ->selectRaw('
                order_items.upid,
                MAX(order_items.title) as title,
                MAX(order_items.sku) as sku,
                SUM(order_items.qty) as units_sold,
                SUM(order_items.line_total) as revenue
            ')
            ->groupBy('order_items.upid')
            ->orderByDesc('units_sold')
            ->limit(5)
            ->get();
    }

    #[Computed]
    public function sellerStatusBreakdown(): array
    {
        if (! $this->canViewOrders()) {
            return [];
        }

        $query = DB::table('seller_orders')
            ->where('seller_profile_id', $this->sellerProfile->id);
        $this->applySellerDateRange($query);

        return $query
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status')
            ->all();
    }

    #[Computed]
    public function sellerOrders()
    {
        if (! $this->canViewOrders()) {
            abort(403);
        }

        $query = SellerOrder::where('seller_profile_id', $this->sellerProfile->id)
            ->with(['order.user', 'items']);
        $this->applySellerDateRange($query);

        return $query->orderByDesc('placed_at')->paginate(10, pageName: 'seller-orders-page');
    }

    public function approveApplication(): void
    {
        $this->authorize(AdminPermissions::SELLERS_MANAGE);

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        $this->sellerProfile->approve($admin);

        Mail::to($this->sellerProfile->user->email)->queue(
            new SellerApplicationApproved($this->sellerProfile)
        );

        AdminActivityLogger::log('seller.approved', $this->sellerProfile, [
            'store_name' => $this->sellerProfile->store_name,
        ]);

        $this->sellerProfile->refresh();
        session()->flash('status', "Application for [{$this->sellerProfile->store_name}] has been approved.");
    }

    public function rejectApplication(): void
    {
        $this->authorize(AdminPermissions::SELLERS_MANAGE);

        $this->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:1000'],
        ]);

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        $this->sellerProfile->reject($admin, $this->rejection_reason);

        Mail::to($this->sellerProfile->user->email)->queue(
            new SellerApplicationRejected($this->sellerProfile, $this->rejection_reason)
        );

        AdminActivityLogger::log('seller.rejected', $this->sellerProfile, [
            'store_name' => $this->sellerProfile->store_name,
            'reason' => $this->rejection_reason,
        ]);

        $this->showRejectForm = false;
        $this->rejection_reason = '';
        $this->sellerProfile->refresh();

        session()->flash('status', "Application for [{$this->sellerProfile->store_name}] has been rejected.");
    }

    public function suspendSeller(): void
    {
        $this->authorize(AdminPermissions::SELLERS_MANAGE);

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        $this->sellerProfile->suspend($admin);
        $this->sellerProfile->refresh();

        AdminActivityLogger::log('seller.suspended', $this->sellerProfile, [
            'store_name' => $this->sellerProfile->store_name,
        ]);

        session()->flash('status', "Seller [{$this->sellerProfile->store_name}] has been suspended.");
    }

    public function reactivateSeller(): void
    {
        $this->authorize(AdminPermissions::SELLERS_MANAGE);

        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        $this->sellerProfile->reactivate($admin);
        $this->sellerProfile->refresh();

        AdminActivityLogger::log('seller.reactivated', $this->sellerProfile, [
            'store_name' => $this->sellerProfile->store_name,
        ]);

        session()->flash('status', "Seller [{$this->sellerProfile->store_name}] has been reactivated.");
    }
}; ?>

<div class="space-y-6">
    <div class="flex items-center gap-2">
        <a href="{{ route('admin.sellers.index') }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            <span>Back to Seller Applications</span>
        </a>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-900/50 dark:bg-green-950/30 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    <!-- Header & Action Controls -->
    <div class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-3">
                @if ($sellerProfile->logoUrl())
                    <img src="{{ $sellerProfile->logoUrl() }}" alt="Logo" class="size-10 rounded-lg object-cover border border-zinc-200 dark:border-zinc-700" />
                @endif
                <h2 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-white" data-test="seller-heading">
                    {{ $sellerProfile->isAnonymized() ? 'Deleted seller' : $sellerProfile->store_name }}
                </h2>
                @if ($sellerProfile->status === SellerStatus::Approved)
                    <span class="inline-flex items-center rounded-full bg-green-50 px-3 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-950/50 dark:text-green-400 border border-green-200 dark:border-green-800">
                        Approved
                    </span>
                @elseif ($sellerProfile->status === SellerStatus::Pending)
                    <span class="inline-flex items-center rounded-full bg-amber-50 px-3 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-950/50 dark:text-amber-400 border border-amber-200 dark:border-amber-800">
                        Pending Review
                    </span>
                @elseif ($sellerProfile->status === SellerStatus::Suspended)
                    <span class="inline-flex items-center rounded-full bg-red-50 px-3 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-950/50 dark:text-red-400 border border-red-200 dark:border-red-800">
                        Suspended
                    </span>
                @elseif ($sellerProfile->status === SellerStatus::Rejected)
                    <span class="inline-flex items-center rounded-full bg-zinc-100 px-3 py-0.5 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-400 border border-zinc-300 dark:border-zinc-700">
                        Rejected
                    </span>
                @endif
            </div>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Applied by <strong>{{ $sellerProfile->isAnonymized() ? 'Deleted user' : $sellerProfile->user->name }}</strong> ({{ $sellerProfile->user->email }}) on {{ $sellerProfile->submitted_at?->format('M j, Y H:i') ?? '—' }}
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            @if (! $sellerProfile->isAnonymized())
                @if ($sellerProfile->status === SellerStatus::Pending)
                    <button wire:click="approveApplication" type="button" data-test="admin-approve-button"
                            class="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700 transition shadow-xs">
                        Approve Application
                    </button>
                    <button wire:click="$toggle('showRejectForm')" type="button" data-test="admin-reject-toggle"
                            class="rounded-lg border border-red-300 bg-white px-4 py-2 text-xs font-semibold text-red-600 hover:bg-red-50 dark:border-red-800 dark:bg-zinc-800 dark:text-red-400 dark:hover:bg-red-950/30 transition shadow-xs">
                        Reject Application
                    </button>
                @elseif ($sellerProfile->status === SellerStatus::Approved)
                    <button wire:click="suspendSeller" type="button" data-test="admin-suspend-button"
                            class="rounded-lg border border-red-300 bg-white px-4 py-2 text-xs font-semibold text-red-600 hover:bg-red-50 dark:border-red-800 dark:bg-zinc-800 dark:text-red-400 dark:hover:bg-red-950/30 transition shadow-xs">
                        Suspend Seller
                    </button>
                @elseif ($sellerProfile->status === SellerStatus::Suspended)
                    <button wire:click="reactivateSeller" type="button" data-test="admin-reactivate-button"
                            class="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700 transition shadow-xs">
                        Reactivate Seller
                    </button>
                @endif
            @endif
        </div>
    </div>

    <!-- Reject Reason Form -->
    @if ($showRejectForm && $sellerProfile->status === SellerStatus::Pending)
        <div class="rounded-xl border border-red-200 bg-red-50/50 p-6 dark:border-red-900/50 dark:bg-red-950/20 shadow-xs">
            <h3 class="text-sm font-semibold text-red-800 dark:text-red-300">Provide Rejection Reason</h3>
            <p class="mt-1 text-xs text-red-600 dark:text-red-400">Explain clearly what the seller must correct or upload before resubmitting.</p>

            <form wire:submit="rejectApplication" class="mt-4 space-y-4">
                <textarea wire:model="rejection_reason" rows="3" required placeholder="e.g. Identity proof is blurry. Please upload a clear color scan of your government-issued ID card."
                          class="block w-full rounded-lg border border-red-300 bg-white p-3 text-sm text-zinc-900 focus:border-red-500 focus:outline-hidden focus:ring-1 focus:ring-red-500 dark:border-red-800 dark:bg-zinc-900 dark:text-white"></textarea>
                @error('rejection_reason') <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror

                <div class="flex gap-2">
                    <button type="submit" data-test="admin-confirm-reject-button" class="rounded-lg bg-red-600 px-4 py-2 text-xs font-semibold text-white hover:bg-red-700 transition shadow-xs">
                        Confirm Rejection & Send Email
                    </button>
                    <button type="button" wire:click="$set('showRejectForm', false)" class="rounded-lg border border-zinc-300 px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    @endif

    <!-- Tab Navigation -->
    <div class="flex items-center gap-6 border-b border-zinc-200 dark:border-zinc-800">
        <button type="button" wire:click="$set('tab', 'overview')" data-test="tab-overview"
                class="pb-3 text-sm font-semibold transition border-b-2 {{ $tab === 'overview' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
            Overview & KYC
        </button>
        @if ($this->canViewOrders())
            <button type="button" wire:click="$set('tab', 'orders')" data-test="tab-orders"
                    class="pb-3 text-sm font-semibold transition border-b-2 {{ $tab === 'orders' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
                Orders & Fulfillment
            </button>
        @endif
    </div>

    @if ($tab === 'overview')
        <!-- Bank Details Changed Alert -->
        @if ($sellerProfile->bank_updated_at)
            <div class="rounded-xl border border-blue-200 bg-blue-50 p-5 dark:border-blue-900/50 dark:bg-blue-950/30">
                <div class="flex items-center gap-3">
                    <svg class="size-5 shrink-0 text-blue-600 dark:text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                    <div class="text-xs text-blue-800 dark:text-blue-300">
                        <strong class="font-semibold text-sm">Bank Details Updated:</strong> The seller updated payout account information on
                        {{ $sellerProfile->bank_updated_at->format('M j, Y H:i:s') }}. Please verify the new bank records.
                    </div>
                </div>
            </div>
        @endif

        <!-- Store & Applicant Information -->
        <div class="grid grid-cols-1 gap-6 lg:grid-cols-2">
            <!-- Store Information -->
            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-sm font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Store Details</h3>
                <dl class="mt-4 space-y-3 text-xs">
                    <div><span class="text-zinc-500">Store Name:</span> <span class="font-medium text-zinc-900 dark:text-white">{{ $sellerProfile->isAnonymized() ? 'Deleted seller' : $sellerProfile->store_name }}</span></div>
                    <div><span class="text-zinc-500">Store Slug:</span> <span class="font-medium text-zinc-900 dark:text-white">/stores/{{ $sellerProfile->store_slug }}</span></div>
                    <div><span class="text-zinc-500">Registered Business Name:</span> <span class="font-medium text-zinc-900 dark:text-white">{{ $sellerProfile->isAnonymized() ? 'Deleted seller' : ($sellerProfile->legal_name ?: 'Not provided yet') }}</span></div>
                    <div><span class="text-zinc-500">Business Type:</span> <span class="font-medium capitalize text-zinc-900 dark:text-white">{{ $sellerProfile->business_type }}</span></div>
                    <div><span class="text-zinc-500">Declaration:</span> <span class="font-medium text-zinc-900 dark:text-white">{{ $sellerProfile->declaration_accepted_at ? 'Accepted '.format_datetime($sellerProfile->declaration_accepted_at) : 'Not accepted yet' }}</span></div>
                    <div><span class="text-zinc-500">Description:</span> <span class="font-medium text-zinc-900 dark:text-white">{{ $sellerProfile->description ?: 'No description provided.' }}</span></div>
                    <div><span class="text-zinc-500">Support Email:</span> <span class="font-medium text-zinc-900 dark:text-white">{{ $sellerProfile->support_email }}</span></div>
                    <div><span class="text-zinc-500">Support Phone:</span> <span class="font-medium text-zinc-900 dark:text-white">{{ $sellerProfile->support_phone }}</span></div>
                </dl>
            </div>

            <!-- Pickup Address -->
            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-sm font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Pickup Address</h3>
                <p class="mt-4 text-xs text-zinc-800 dark:text-zinc-200 leading-relaxed">
                    {{ $sellerProfile->pickup_address_line1 }}
                    @if ($sellerProfile->pickup_address_line2)
                        , {{ $sellerProfile->pickup_address_line2 }}
                    @endif
                    <br>
                    {{ $sellerProfile->pickup_city }}, {{ $sellerProfile->pickup_state }} - {{ $sellerProfile->pickup_postal_code }}
                    <br>
                    {{ $sellerProfile->pickup_country }}
                </p>

                <h3 class="mt-6 text-sm font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Review Audit</h3>
                <dl class="mt-2 space-y-2 text-xs">
                    <div><span class="text-zinc-500">Last Reviewed By:</span> <span class="font-medium text-zinc-900 dark:text-white">{{ $sellerProfile->reviewer?->name ?? 'Not yet reviewed' }}</span></div>
                    <div><span class="text-zinc-500">Reviewed At:</span> <span class="font-medium text-zinc-900 dark:text-white">{{ $sellerProfile->reviewed_at?->format('M j, Y H:i') ?? '—' }}</span></div>
                    @if ($sellerProfile->rejection_reason)
                        <div><span class="text-red-500 font-semibold">Rejection Reason:</span> <span class="text-red-600 dark:text-red-400">{{ $sellerProfile->rejection_reason }}</span></div>
                    @endif
                </dl>
            </div>
        </div>

        <!-- Tax & Bank Details -->
        <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Tax & Bank Account Records (Encrypted in Storage)</h3>
                @if (! $this->canViewKyc())
                    <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2.5 py-0.5 text-[11px] font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-400 border border-amber-200 dark:border-amber-800">
                        <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                        </svg>
                        <span>Masked (KYC permission required)</span>
                    </span>
                @endif
            </div>
            <dl class="mt-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 text-xs">
                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-3 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-zinc-500">PAN Card</dt>
                    <dd class="mt-1 font-semibold uppercase text-zinc-900 dark:text-white" data-test="pan-value">{{ $this->maskKycField($sellerProfile->pan) }}</dd>
                </div>
                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-3 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-zinc-500">GSTIN</dt>
                    <dd class="mt-1 font-semibold uppercase text-zinc-900 dark:text-white" data-test="gstin-value">{{ $this->maskKycField($sellerProfile->gstin) }}</dd>
                </div>
                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-3 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-zinc-500">Beneficiary Name</dt>
                    <dd class="mt-1 font-semibold text-zinc-900 dark:text-white">{{ $sellerProfile->isAnonymized() ? '—' : $sellerProfile->bank_account_holder }}</dd>
                </div>
                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-3 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-zinc-500">Account Number & IFSC</dt>
                    <dd class="mt-1 font-semibold text-zinc-900 dark:text-white" data-test="bank-account-value">
                        @if ($sellerProfile->isAnonymized())
                            —
                        @else
                            {{ $this->maskKycField($sellerProfile->bank_account_number) }} ({{ $sellerProfile->ifsc }})
                        @endif
                    </dd>
                </div>
            </dl>
        </div>

        <!-- KYC Documents Section -->
        <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <h3 class="text-sm font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Uploaded KYC Documents (Private Storage)</h3>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">Stored on private disk and accessible exclusively via authorized admin stream.</p>

            <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                @forelse ($sellerProfile->documents as $doc)
                    <div class="flex items-center justify-between rounded-lg border border-zinc-200 p-4 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-800/30">
                        <div>
                            <div class="text-xs font-semibold uppercase text-zinc-500 dark:text-zinc-400">
                                {{ str_replace('_', ' ', $doc->type) }}
                            </div>
                            <div class="mt-1 text-sm font-medium text-zinc-900 dark:text-white">
                                {{ $doc->original_name }}
                            </div>
                        </div>
                        @if ($this->canViewKyc())
                            <a href="{{ route('admin.sellers.documents.show', $doc) }}" target="_blank"
                               class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700 transition"
                               data-test="kyc-doc-download">
                                Download / View
                            </a>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-lg border border-zinc-200 bg-zinc-100 px-3 py-1.5 text-xs font-medium text-zinc-400 dark:border-zinc-800 dark:bg-zinc-800/60 dark:text-zinc-500 cursor-not-allowed">
                                <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                </svg>
                                <span>Locked</span>
                            </span>
                        @endif
                    </div>
                @empty
                    <div class="col-span-2 text-center py-6 text-xs text-zinc-400">
                        No KYC documents uploaded yet.
                    </div>
                @endforelse
            </div>
        </div>
    @elseif ($tab === 'orders')
        <!-- Orders Tab Content -->
        <div class="space-y-6" data-test="seller-orders-tab-content">
            <!-- Filter Bar: Date Presets & Custom Dates -->
            <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs font-semibold text-zinc-500 dark:text-zinc-400 mr-1">Placed:</span>
                    @foreach (['all' => 'All Time', 'today' => 'Today', '7_days' => 'Last 7 Days', '30_days' => 'Last 30 Days', 'custom' => 'Custom'] as $presetKey => $presetLabel)
                        <button type="button" wire:click="$set('ordersDatePreset', '{{ $presetKey }}')"
                                class="rounded-lg px-2.5 py-1 text-xs font-medium transition {{ $ordersDatePreset === $presetKey ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'bg-zinc-100 text-zinc-700 hover:bg-zinc-200 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700' }}"
                                data-test="date-preset-{{ $presetKey }}">
                            {{ $presetLabel }}
                        </button>
                    @endforeach
                </div>

                @if ($ordersDatePreset === 'custom')
                    <div class="flex items-center gap-2 text-xs">
                        <input type="date" wire:model.live="ordersDateFrom" class="rounded-lg border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        <span class="text-zinc-400">to</span>
                        <input type="date" wire:model.live="ordersDateTo" class="rounded-lg border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                    </div>
                @endif
            </div>

            <!-- Summary Bar -->
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 block">Total Orders</span>
                    <span class="text-xl font-bold text-zinc-900 dark:text-white mt-1 block" data-test="seller-summary-orders-count">
                        {{ number_format($this->sellerOrdersSummary['total_orders']) }}
                    </span>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 block">Net Fulfillment Value</span>
                    <span class="text-xl font-bold text-emerald-600 dark:text-emerald-400 mt-1 block" data-test="seller-summary-net-value">
                        ₹{{ number_format($this->sellerOrdersSummary['net_value'] / 100) }}
                    </span>
                    <span class="text-[10px] text-zinc-400 mt-0.5 block" data-test="seller-summary-gross-value">
                        Gross: ₹{{ number_format($this->sellerOrdersSummary['gross_value'] / 100) }}
                    </span>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 block">Cancelled Value</span>
                    <span class="text-xl font-bold text-red-600 dark:text-red-400 mt-1 block" data-test="seller-summary-cancelled-value">
                        ₹{{ number_format($this->sellerOrdersSummary['cancelled_value'] / 100) }}
                    </span>
                </div>

                <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400 block">Items Sold</span>
                    <span class="text-xl font-bold text-indigo-600 dark:text-indigo-400 mt-1 block" data-test="seller-summary-items-sold">
                        {{ number_format($this->sellerOrdersSummary['items_sold']) }}
                    </span>
                </div>
            </div>

            <!-- Orders by Status Breakdown -->
            @if (!empty($this->sellerStatusBreakdown))
                <div class="flex flex-wrap items-center gap-2 text-xs">
                    <span class="font-semibold text-zinc-400 mr-1">Packages by Status:</span>
                    @foreach ($this->sellerStatusBreakdown as $statusName => $count)
                        <span class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 font-medium text-zinc-700 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                            <span class="capitalize">{{ $statusName }}:</span>
                            <strong class="font-bold text-zinc-900 dark:text-white">{{ $count }}</strong>
                        </span>
                    @endforeach
                </div>
            @endif

            <!-- Top 5 Products by Quantity Sold -->
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 mb-3">Top 5 Products by Units Sold</h3>
                @if ($this->sellerTopProducts->isNotEmpty())
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="border-b border-zinc-100 text-zinc-400 font-bold dark:border-zinc-800">
                                <tr>
                                    <th class="py-2.5 px-3">Product Title</th>
                                    <th class="py-2.5 px-3">UPID</th>
                                    <th class="py-2.5 px-3">SKU</th>
                                    <th class="py-2.5 px-3 text-right">Units Sold</th>
                                    <th class="py-2.5 px-3 text-right">Revenue</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @foreach ($this->sellerTopProducts as $topProd)
                                    <tr data-test="top-product-row">
                                        <td class="py-2.5 px-3 font-semibold text-zinc-900 dark:text-white" data-test="top-product-title">{{ $topProd->title }}</td>
                                        <td class="py-2.5 px-3 font-mono text-[11px] text-zinc-500" data-test="top-product-upid">{{ $topProd->upid }}</td>
                                        <td class="py-2.5 px-3 font-mono text-[11px] text-zinc-500">{{ $topProd->sku }}</td>
                                        <td class="py-2.5 px-3 text-right font-bold text-zinc-900 dark:text-white" data-test="top-product-units">{{ number_format($topProd->units_sold) }}</td>
                                        <td class="py-2.5 px-3 text-right font-semibold text-emerald-600 dark:text-emerald-400" data-test="top-product-revenue">₹{{ number_format($topProd->revenue / 100) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @else
                    <p class="text-xs text-zinc-400 py-2">No product sales recorded for this period.</p>
                @endif
            </div>

            <!-- Seller Orders Paginated Table -->
            <div class="rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                <div class="p-4 border-b border-zinc-100 dark:border-zinc-800">
                    <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400">Seller Fulfillment Packages</h3>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left text-xs">
                        <thead class="border-b border-zinc-100 bg-zinc-50/50 text-zinc-400 font-bold dark:border-zinc-800 dark:bg-zinc-800/30">
                            <tr>
                                <th class="py-3 px-4">Order #</th>
                                <th class="py-3 px-4">Date</th>
                                <th class="py-3 px-4">Customer</th>
                                <th class="py-3 px-4">Items</th>
                                <th class="py-3 px-4 text-right">Package Amount</th>
                                <th class="py-3 px-4">Status</th>
                                <th class="py-3 px-4 text-right">Action</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                            @forelse ($this->sellerOrders as $sOrder)
                                <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40 transition" data-test="seller-order-row">
                                    <td class="py-3 px-4">
                                        <a href="{{ route('admin.orders.show', $sOrder->order_id) }}" wire:navigate class="font-bold text-zinc-900 hover:text-emerald-600 dark:text-white dark:hover:text-emerald-400" data-test="order-number-link">
                                            {{ $sOrder->order->order_number }}
                                        </a>
                                    </td>
                                    <td class="py-3 px-4 text-zinc-500 whitespace-nowrap">
                                        @datetime($sOrder->placed_at)
                                    </td>
                                    <td class="py-3 px-4 text-zinc-700 dark:text-zinc-300">
                                        @if ($sOrder->order?->user?->isAnonymized())
                                            <span class="text-zinc-500 italic">Deleted user</span>
                                        @elseif ($this->canViewCustomers() && $sOrder->order?->user_id)
                                            <a href="{{ route('admin.customers.show', $sOrder->order->user_id) }}" wire:navigate class="hover:underline font-medium">
                                                {{ $sOrder->order->user?->name ?? 'Deleted user' }}
                                            </a>
                                        @else
                                            <span>{{ $sOrder->order?->user?->name ?? 'Deleted user' }}</span>
                                        @endif
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="font-semibold text-zinc-900 dark:text-white">{{ $sOrder->items->count() }}</span>
                                        <span class="text-zinc-400 text-[11px]">
                                            ({{ \Illuminate\Support\Str::limit($sOrder->items->first()?->title ?? '', 20) }})
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-right font-bold text-zinc-900 dark:text-white whitespace-nowrap">
                                        ₹{{ number_format($sOrder->grand_total / 100) }}
                                    </td>
                                    <td class="py-3 px-4">
                                        <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-bold uppercase {{ $sOrder->status->value === 'cancelled' ? 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300' : ($sOrder->status->value === 'delivered' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' : 'bg-blue-50 text-blue-700 dark:bg-blue-950/50 dark:text-blue-300') }}">
                                            {{ $sOrder->status->label() }}
                                        </span>
                                    </td>
                                    <td class="py-3 px-4 text-right whitespace-nowrap">
                                        <a href="{{ route('admin.orders.show', $sOrder->order_id) }}" wire:navigate class="text-xs font-semibold text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 hover:underline">
                                            View Order &rarr;
                                        </a>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="7" class="py-8 text-center text-xs text-zinc-400">
                                        No fulfillment packages found for this seller.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                @if ($this->sellerOrders->hasPages())
                    <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
                        {{ $this->sellerOrders->links() }}
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
