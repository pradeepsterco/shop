<?php

use App\Mail\PasswordChangedByAdminMail;
use App\Models\User;
use App\Orders\Models\Order;
use App\Orders\Models\SellerOrder;
use App\Services\AdminActivityLogger;
use App\Support\AdminPermissions;
use App\Support\PasswordGenerator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Customer Details')] class extends Component
{
    use WithPagination;

    public User $user;

    #[Url]
    public string $tab = 'account'; // 'account' or 'orders'

    // Reset Password Modal state
    public bool $showResetPasswordModal = false;

    public string $resetMode = 'link'; // 'link' or 'password'

    public string $newPassword = '';

    public string $newPassword_confirmation = '';

    public ?string $resetGeneratedPassword = null;

    public bool $resetMustChangePassword = true;

    public function mount(User $user): void
    {
        $this->authorize(AdminPermissions::CUSTOMERS_VIEW);
        $this->user = $user;

        if ($this->tab === 'orders' && ! $this->canViewOrders()) {
            abort(403);
        }
    }

    public function updatedTab(string $value): void
    {
        if ($value === 'orders' && ! $this->canViewOrders()) {
            abort(403);
        }
        $this->resetPage('customer-orders-page');
    }

    public function canViewOrders(): bool
    {
        return Auth::guard('admin')->user()?->can(AdminPermissions::ORDERS_VIEW) ?? false;
    }

    #[Computed]
    public function customerOrdersSummary(): array
    {
        if (! $this->canViewOrders()) {
            return [
                'total_orders' => 0,
                'gross_value' => 0,
                'cancelled_value' => 0,
                'total_spent' => 0,
                'cancelled_orders_count' => 0,
                'last_order_at' => null,
                'aov' => 0,
            ];
        }

        $totalOrders = Order::where('user_id', $this->user->id)->count();

        $sellerTotals = DB::table('seller_orders')
            ->join('orders', 'seller_orders.order_id', '=', 'orders.id')
            ->where('orders.user_id', $this->user->id)
            ->selectRaw('
                COALESCE(SUM(seller_orders.grand_total), 0) as gross_value,
                COALESCE(SUM(CASE WHEN seller_orders.status = "cancelled" THEN seller_orders.grand_total ELSE 0 END), 0) as cancelled_value
            ')
            ->first();

        $grossValue = (int) ($sellerTotals->gross_value ?? 0);
        $cancelledValue = (int) ($sellerTotals->cancelled_value ?? 0);
        $netValue = max(0, $grossValue - $cancelledValue);
        $totalSpent = $netValue;

        $cancelledOrdersCount = Order::where('user_id', $this->user->id)
            ->where('status', 'cancelled')
            ->count();

        $lastOrderAt = Order::where('user_id', $this->user->id)->max('placed_at');

        $activeOrdersCount = DB::table('orders')
            ->where('orders.user_id', $this->user->id)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('seller_orders')
                    ->whereColumn('seller_orders.order_id', 'orders.id')
                    ->where('seller_orders.status', '!=', 'cancelled');
            })
            ->count();

        $aov = $activeOrdersCount > 0 ? (int) round($netValue / $activeOrdersCount) : 0;

        return [
            'total_orders' => $totalOrders,
            'gross_value' => $grossValue,
            'cancelled_value' => $cancelledValue,
            'total_spent' => $totalSpent,
            'cancelled_orders_count' => $cancelledOrdersCount,
            'last_order_at' => $lastOrderAt ? Carbon::parse($lastOrderAt) : null,
            'aov' => $aov,
        ];
    }

    #[Computed]
    public function customerOrders()
    {
        if (! $this->canViewOrders()) {
            abort(403);
        }

        return Order::where('user_id', $this->user->id)
            ->with(['sellerOrders.items', 'sellerOrders.sellerProfile'])
            ->orderByDesc('placed_at')
            ->paginate(10, pageName: 'customer-orders-page');
    }

    public function openResetPasswordModal(): void
    {
        $this->authorize(AdminPermissions::CUSTOMERS_PASSWORD);

        $this->resetMode = 'link';
        $this->resetMustChangePassword = true;
        $this->reset([
            'newPassword',
            'newPassword_confirmation',
            'resetGeneratedPassword',
        ]);
        $this->resetErrorBag();
        $this->showResetPasswordModal = true;
    }

    public function closeResetPasswordModal(): void
    {
        $this->showResetPasswordModal = false;
        $this->reset([
            'resetMode',
            'newPassword',
            'newPassword_confirmation',
            'resetGeneratedPassword',
            'resetMustChangePassword',
        ]);
        $this->resetErrorBag();
    }

    public function generateResetPassword(): void
    {
        $this->resetGeneratedPassword = PasswordGenerator::generate();
        $this->newPassword = $this->resetGeneratedPassword;
        $this->newPassword_confirmation = $this->resetGeneratedPassword;
    }

    public function confirmResetPassword(): void
    {
        $this->authorize(AdminPermissions::CUSTOMERS_PASSWORD);

        if ($this->resetMode === 'link') {
            $token = Password::broker('users')->createToken($this->user);
            $this->user->sendPasswordResetNotification($token);

            AdminActivityLogger::log('customer.reset_link_sent', $this->user, [
                'email' => $this->user->email,
            ]);

            session()->flash('status', "Password reset link sent to [{$this->user->email}].");
        } else {
            $this->validate([
                'newPassword' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
            ]);

            $this->user->forceFill([
                'password' => Hash::make($this->newPassword),
                'must_change_password' => $this->resetMustChangePassword,
                'remember_token' => null,
            ])->save();

            // Clear sessions for this customer from database sessions table
            DB::table('sessions')->where('user_id', $this->user->id)->delete();

            // Notify customer by email
            Mail::to($this->user->email)->queue(new PasswordChangedByAdminMail($this->user));

            AdminActivityLogger::log('customer.password_reset', $this->user, [
                'email' => $this->user->email,
                'must_change_password' => $this->resetMustChangePassword,
            ]);

            session()->flash('status', "Password for customer [{$this->user->email}] has been reset successfully.");
        }

        $this->closeResetPasswordModal();
    }

    public function toggleActive(): void
    {
        $this->authorize(AdminPermissions::CUSTOMERS_MANAGE);

        $this->user->forceFill(['is_active' => ! $this->user->is_active])->save();

        AdminActivityLogger::log($this->user->is_active ? 'customer.activated' : 'customer.deactivated', $this->user, [
            'user_id' => $this->user->id,
            'email' => $this->user->email,
            'is_active' => $this->user->is_active,
        ]);

        session()->flash('status', 'Customer status updated to '.($this->user->is_active ? 'active' : 'inactive').'.');
    }
}; ?>

<div class="space-y-6">
    <div class="flex items-center gap-2">
        <a href="{{ route('admin.customers') }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            <span>Back to Customers</span>
        </a>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-900/50 dark:bg-green-950/30 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-col gap-4 border-b border-zinc-200 p-6 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
            <div class="flex items-center gap-4">
                <div class="flex size-14 items-center justify-center rounded-full bg-zinc-200 text-lg font-bold text-zinc-800 dark:bg-zinc-700 dark:text-zinc-100">
                    {{ $user->initials() }}
                </div>
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-white" data-test="customer-name">
                        {{ $user->isAnonymized() ? 'Deleted user' : $user->name }}
                    </h2>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400" data-test="customer-email">{{ $user->email }}</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                @if ($user->is_active)
                    <span class="inline-flex items-center rounded-full bg-green-50 px-3 py-1 text-xs font-medium text-green-700 dark:bg-green-950/50 dark:text-green-400 border border-green-200 dark:border-green-800">
                        Active Account
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full bg-zinc-100 px-3 py-1 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 border border-zinc-300 dark:border-zinc-700">
                        Inactive Account
                    </span>
                @endif

                @if (! $user->isAnonymized())
                    @can('customers.password')
                        <button wire:click="openResetPasswordModal"
                                type="button"
                                class="rounded-lg border border-amber-300 px-3.5 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300 dark:hover:bg-amber-950/30 shadow-xs transition">
                            Reset Password
                        </button>
                    @endcan

                    @can('customers.manage')
                        <button wire:click="toggleActive"
                                type="button"
                                class="rounded-lg px-3.5 py-2 text-xs font-semibold shadow-xs transition {{ $user->is_active ? 'bg-red-600 text-white hover:bg-red-700 dark:bg-red-500 dark:hover:bg-red-600' : 'bg-green-600 text-white hover:bg-green-700 dark:bg-green-500 dark:hover:bg-green-600' }}">
                            {{ $user->is_active ? 'Deactivate Customer' : 'Activate Customer' }}
                        </button>
                    @endcan
                @endif
            </div>
        </div>

        <!-- Tab Navigation -->
        <div class="flex items-center gap-6 px-6 pt-4 border-b border-zinc-200 dark:border-zinc-800">
            <button type="button" wire:click="$set('tab', 'account')" data-test="tab-account"
                    class="pb-3 text-sm font-semibold transition border-b-2 {{ $tab === 'account' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
                Account Information
            </button>
            @if ($this->canViewOrders())
                <button type="button" wire:click="$set('tab', 'orders')" data-test="tab-orders"
                        class="pb-3 text-sm font-semibold transition border-b-2 {{ $tab === 'orders' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
                    Orders & Spending
                </button>
            @endif
        </div>

        @if ($tab === 'account')
            <div class="p-6">
                <h3 class="text-sm font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Account Information</h3>
                <dl class="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Customer ID</dt>
                        <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">#{{ $user->id }}</dd>
                    </div>

                    <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Full Name</dt>
                        <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $user->isAnonymized() ? 'Deleted user' : $user->name }}</dd>
                    </div>

                    <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Email Address</dt>
                        <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $user->email }}</dd>
                    </div>

                    <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Phone Number</dt>
                        <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $user->phone ?? '—' }}</dd>
                    </div>

                    <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Password Change Pending</dt>
                        <dd class="mt-1 text-sm font-semibold {{ $user->must_change_password ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-900 dark:text-white' }}">
                            {{ $user->must_change_password ? 'Yes (Forced on next login)' : 'No' }}
                        </dd>
                    </div>

                    <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Email Verified At</dt>
                        <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">
                            {{ $user->email_verified_at ? format_datetime($user->email_verified_at) : 'Not verified' }}
                        </dd>
                    </div>

                    <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Registered On</dt>
                        <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">
                            @datetime($user->created_at)
                        </dd>
                    </div>

                    <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                        <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Last Updated</dt>
                        <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">
                            @datetime($user->updated_at)
                        </dd>
                    </div>
                </dl>
            </div>
        @elseif ($tab === 'orders')
            <div class="p-6 space-y-6" data-test="customer-orders-tab-content">
                <!-- Customer Spending & Orders Summary Metrics -->
                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-800/30">
                        <span class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400 block">Total Orders</span>
                        <span class="text-lg font-bold text-zinc-900 dark:text-white mt-1 block" data-test="customer-summary-orders-count">
                            {{ number_format($this->customerOrdersSummary['total_orders']) }}
                        </span>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-800/30">
                        <span class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400 block">Total Spent (Net)</span>
                        <span class="text-lg font-bold text-emerald-600 dark:text-emerald-400 mt-1 block" data-test="customer-summary-total-spent">
                            ₹{{ number_format($this->customerOrdersSummary['total_spent'] / 100) }}
                        </span>
                        <span class="text-[9px] text-zinc-400 mt-0.5 block" data-test="customer-summary-gross-value">
                            Gross: ₹{{ number_format($this->customerOrdersSummary['gross_value'] / 100) }}
                        </span>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-800/30">
                        <span class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400 block">Cancelled Value</span>
                        <span class="text-lg font-bold text-red-600 dark:text-red-400 mt-1 block" data-test="customer-summary-cancelled-value">
                            ₹{{ number_format($this->customerOrdersSummary['cancelled_value'] / 100) }}
                        </span>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-800/30">
                        <span class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400 block">Cancelled Orders</span>
                        <span class="text-lg font-bold text-zinc-700 dark:text-zinc-300 mt-1 block" data-test="customer-summary-cancelled-orders">
                            {{ number_format($this->customerOrdersSummary['cancelled_orders_count']) }}
                        </span>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-800/30">
                        <span class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400 block">Avg Order (AOV)</span>
                        <span class="text-lg font-bold text-indigo-600 dark:text-indigo-400 mt-1 block" data-test="customer-summary-aov">
                            ₹{{ number_format($this->customerOrdersSummary['aov'] / 100) }}
                        </span>
                    </div>

                    <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-3.5 shadow-xs dark:border-zinc-800 dark:bg-zinc-800/30">
                        <span class="text-[11px] font-medium text-zinc-500 dark:text-zinc-400 block">Last Order</span>
                        <span class="text-xs font-bold text-zinc-900 dark:text-white mt-1.5 block" data-test="customer-summary-last-order">
                            {{ $this->customerOrdersSummary['last_order_at'] ? format_date($this->customerOrdersSummary['last_order_at']) : 'Never' }}
                        </span>
                    </div>
                </div>

                <!-- Customer Orders Paginated Table -->
                <div class="rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900 overflow-hidden">
                    <div class="p-4 border-b border-zinc-100 dark:border-zinc-800">
                        <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400">Order History</h3>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="w-full text-left text-xs">
                            <thead class="border-b border-zinc-100 bg-zinc-50/50 text-zinc-400 font-bold dark:border-zinc-800 dark:bg-zinc-800/30">
                                <tr>
                                    <th class="py-3 px-4">Order #</th>
                                    <th class="py-3 px-4">Date</th>
                                    <th class="py-3 px-4">Sellers Involved</th>
                                    <th class="py-3 px-4">Items</th>
                                    <th class="py-3 px-4 text-right">Grand Total</th>
                                    <th class="py-3 px-4">Status</th>
                                    <th class="py-3 px-4 text-right">Action</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                @forelse ($this->customerOrders as $cOrder)
                                    <tr class="hover:bg-zinc-50/50 dark:hover:bg-zinc-800/40 transition" data-test="customer-order-row">
                                        <td class="py-3 px-4">
                                            <a href="{{ route('admin.orders.show', $cOrder->id) }}" wire:navigate class="font-bold text-zinc-900 hover:text-emerald-600 dark:text-white dark:hover:text-emerald-400" data-test="order-number-link">
                                                {{ $cOrder->order_number }}
                                            </a>
                                        </td>
                                        <td class="py-3 px-4 text-zinc-500 whitespace-nowrap">
                                            @datetime($cOrder->placed_at)
                                        </td>
                                        <td class="py-3 px-4 text-zinc-700 dark:text-zinc-300">
                                            @php
                                                $stores = $cOrder->sellerOrders->pluck('seller_store_name')->filter()->unique();
                                            @endphp
                                            @if ($stores->count() <= 2)
                                                <span>{{ $stores->implode(', ') }}</span>
                                            @else
                                                <span>{{ $stores->first() }}</span>
                                                <span class="text-zinc-400 text-[11px]">+{{ $stores->count() - 1 }} more</span>
                                            @endif
                                        </td>
                                        <td class="py-3 px-4 font-semibold text-zinc-900 dark:text-white">
                                            {{ $cOrder->sellerOrders->sum(fn ($so) => $so->items->count()) }}
                                        </td>
                                        <td class="py-3 px-4 text-right font-bold text-zinc-900 dark:text-white whitespace-nowrap">
                                            ₹{{ number_format($cOrder->grand_total / 100) }}
                                        </td>
                                        <td class="py-3 px-4">
                                            <span class="inline-flex items-center rounded-md px-2 py-0.5 text-[11px] font-bold uppercase {{ $cOrder->status->value === 'cancelled' ? 'bg-red-50 text-red-700 dark:bg-red-950/50 dark:text-red-300' : ($cOrder->status->value === 'completed' ? 'bg-emerald-50 text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-300' : 'bg-amber-50 text-amber-800 dark:bg-amber-950/50 dark:text-amber-200') }}">
                                                {{ $cOrder->status->label() }}
                                            </span>
                                        </td>
                                        <td class="py-3 px-4 text-right whitespace-nowrap">
                                            <a href="{{ route('admin.orders.show', $cOrder->id) }}" wire:navigate class="text-xs font-semibold text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 hover:underline">
                                                View Order &rarr;
                                            </a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="7" class="py-8 text-center text-xs text-zinc-400">
                                            No orders placed by this customer.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($this->customerOrders->hasPages())
                        <div class="p-4 border-t border-zinc-100 dark:border-zinc-800">
                            {{ $this->customerOrders->links() }}
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

    <!-- Reset Customer Password Modal -->
    @if ($showResetPasswordModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div wire:click="closeResetPasswordModal" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900 space-y-5">
                <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
                    <div>
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white">Reset Customer Password</h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 font-mono">{{ $user->email }}</p>
                    </div>
                    <button wire:click="closeResetPasswordModal" type="button" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        ✕
                    </button>
                </div>

                <form wire:submit="confirmResetPassword" class="space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center gap-2 p-2.5 rounded-lg border border-zinc-200 dark:border-zinc-800 cursor-pointer {{ $resetMode === 'link' ? 'bg-zinc-100 dark:bg-zinc-800 border-zinc-400' : '' }}">
                            <input type="radio" wire:model.live="resetMode" value="link" class="size-3.5 text-zinc-900">
                            <span class="text-xs font-medium text-zinc-900 dark:text-white">Send reset link</span>
                        </label>
                        <label class="flex items-center gap-2 p-2.5 rounded-lg border border-zinc-200 dark:border-zinc-800 cursor-pointer {{ $resetMode === 'password' ? 'bg-zinc-100 dark:bg-zinc-800 border-zinc-400' : '' }}">
                            <input type="radio" wire:model.live="resetMode" value="password" class="size-3.5 text-zinc-900">
                            <span class="text-xs font-medium text-zinc-900 dark:text-white">Set new password</span>
                        </label>
                    </div>

                    @if ($resetMode === 'password')
                        <div class="space-y-3 rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-800/40">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-zinc-900 dark:text-white">New Password</span>
                                <button wire:click="generateResetPassword" type="button" class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                                    ⚡ Generate strong password
                                </button>
                            </div>

                            @if ($resetGeneratedPassword)
                                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs dark:border-amber-900/50 dark:bg-amber-950/40">
                                    <div class="font-semibold text-amber-900 dark:text-amber-200">Generated Password (shown once):</div>
                                    <div class="mt-1.5 flex items-center justify-between gap-2">
                                        <code class="rounded bg-white/80 px-2 py-1 font-mono text-xs text-zinc-900 dark:bg-zinc-900 dark:text-white select-all">{{ $resetGeneratedPassword }}</code>
                                        <button type="button"
                                                onclick="navigator.clipboard.writeText('{{ $resetGeneratedPassword }}'); alert('Password copied to clipboard!');"
                                                class="rounded bg-amber-200 px-2 py-1 text-[11px] font-semibold text-amber-900 hover:bg-amber-300 dark:bg-amber-800 dark:text-amber-100">
                                            Copy
                                        </button>
                                    </div>
                                    <p class="mt-1 text-[10px] text-amber-700 dark:text-amber-400">Copy this password now. It will not be shown again after closing.</p>
                                </div>
                            @endif

                            <div>
                                <label for="newPassword" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Password</label>
                                <input wire:model="newPassword" id="newPassword" type="password" required
                                       class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                @error('newPassword')
                                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="newPassword_confirmation" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Confirm Password</label>
                                <input wire:model="newPassword_confirmation" id="newPassword_confirmation" type="password" required
                                       class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            </div>

                            <label class="flex items-center gap-2 pt-1 text-xs cursor-pointer">
                                <input type="checkbox" wire:model="resetMustChangePassword" class="size-3.5 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800" />
                                <span class="text-zinc-700 dark:text-zinc-300 font-medium">Require password change on next login</span>
                            </label>
                        </div>
                    @else
                        <p class="text-xs text-zinc-600 dark:text-zinc-400">
                            A secure password reset link will be sent to <strong>{{ $user->email }}</strong>.
                        </p>
                    @endif

                    <div class="flex items-center justify-end gap-3 pt-3 border-t border-zinc-200 dark:border-zinc-800">
                        <button wire:click="closeResetPasswordModal" type="button" class="rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                            Cancel
                        </button>
                        <button type="submit" class="rounded-lg bg-zinc-900 px-5 py-2 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100">
                            {{ $resetMode === 'link' ? 'Send Reset Link' : 'Save New Password' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
