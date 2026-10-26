<?php

namespace App\Livewire\Admin;

use App\Shipping\Contracts\CarrierManagerInterface;
use App\Shipping\Contracts\ShippingCarrierInterface;
use App\Shipping\Drivers\EkartCarrierDriver;
use App\Shipping\Drivers\ShiprocketCarrierDriver;
use App\Shipping\Models\SellerShippingProfile;
use Illuminate\Support\Facades\Artisan;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Shipping Settings')] class extends Component {
    public string $activeDriver = 'ekart';

    public ?string $testConnectionResult = null;

    public bool $testConnectionSuccess = false;

    public function mount(CarrierManagerInterface $carrierManager): void
    {
        $this->authorize('shipping.view');
        $this->activeDriver = $carrierManager->getActiveDriverName();
    }

    #[Computed]
    public function warehouseStats(): array
    {
        $query = SellerShippingProfile::where('provider', $this->activeDriver);
        $total = (clone $query)->count();
        $registered = (clone $query)->where('registration_status', 'registered')->count();
        $pending = (clone $query)->where('registration_status', 'pending_verification')->count();
        $failed = (clone $query)->where('registration_status', 'failed')->count();
        $otherPending = $total - $registered - $pending - $failed;

        return [
            'total' => $total,
            'registered' => $registered,
            'failed' => $failed,
            'pending' => max(0, $pending + max(0, $otherPending)),
        ];
    }

    #[Computed]
    public function webhookConfigured(): bool
    {
        $header = config('shipping.ekart.webhook_header');
        $algo = config('shipping.ekart.webhook_algorithm');

        return ! empty($header) && ! empty($algo);
    }

    #[Computed]
    public function shiprocketWebhookConfigured(): bool
    {
        return ! empty(config('shipping.shiprocket.webhook_token'));
    }

    #[Computed]
    public function shiprocketWalletBalance(): ?float
    {
        try {
            /** @var CarrierManagerInterface $carrierManager */
            $carrierManager = app(CarrierManagerInterface::class);
            $driver = $carrierManager->driver('shiprocket');
            if ($driver instanceof ShiprocketCarrierDriver) {
                return $driver->getWalletBalance();
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    public function testConnection(CarrierManagerInterface $carrierManager): void
    {
        $this->authorize('shipping.manage');

        try {
            $driver = $carrierManager->driver($this->activeDriver);

            if ($driver instanceof EkartCarrierDriver) {
                $token = $driver->getAccessToken();
                $this->testConnectionSuccess = ! empty($token);
                $this->testConnectionResult = 'Connection successful! Ekart OAuth token verified and cached.';
            } elseif ($driver instanceof ShiprocketCarrierDriver) {
                $token = $driver->getAccessToken();
                $this->testConnectionSuccess = ! empty($token);
                $this->testConnectionResult = 'Connection successful! Shiprocket token verified and cached.';
            } else {
                $this->testConnectionSuccess = true;
                $this->testConnectionResult = "Driver [{$this->activeDriver}] is active and operational.";
            }

            session()->flash('settings_success', $this->testConnectionResult);
        } catch (\Throwable $e) {
            $this->testConnectionSuccess = false;
            $this->testConnectionResult = 'Connection failed: '.$e->getMessage();
            session()->flash('settings_error', $this->testConnectionResult);
        }
    }

    public function syncWarehouses(): void
    {
        $this->authorize('shipping.manage');

        try {
            Artisan::call('shipping:sync-warehouses');
            $output = Artisan::output();

            session()->flash('settings_success', 'Seller warehouse synchronization completed: '.$output);
            unset($this->warehouseStats);
        } catch (\Throwable $e) {
            session()->flash('settings_error', 'Warehouse synchronization failed: '.$e->getMessage());
        }
    }
}; ?>

<div class="space-y-6">
    <div class="flex items-center justify-between">
        <div>
            <h1 class="text-xl font-extrabold text-zinc-900 dark:text-white">Shipping & Carrier Settings</h1>
            <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">Manage active shipping carriers, credentials, webhooks, and warehouse integrations.</p>
        </div>
    </div>

    @if (session()->has('settings_success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-semibold text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/50 dark:text-emerald-300">
            {{ session('settings_success') }}
        </div>
    @endif
    @if (session()->has('settings_error'))
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-xs font-semibold text-red-800 dark:border-red-900/50 dark:bg-red-950/50 dark:text-red-300">
            {{ session('settings_error') }}
        </div>
    @endif

    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
        <!-- Active Carrier Driver -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                Active Logistics Partner
            </h3>

            <div>
                <label class="block text-xs font-bold text-zinc-700 dark:text-zinc-300 mb-1">Carrier Provider</label>
                <div class="flex items-center gap-3">
                    <span class="inline-flex items-center rounded-lg bg-indigo-50 px-3 py-1.5 text-xs font-extrabold text-indigo-700 ring-1 ring-inset ring-indigo-700/20 dark:bg-indigo-950/50 dark:text-indigo-300">
                        {{ strtoupper($activeDriver) }} ({{ $activeDriver === 'shiprocket' ? 'Shiprocket Logistics' : ($activeDriver === 'ekart' ? 'Ekart Logistics' : ucfirst($activeDriver)) }})
                    </span>
                    <span class="text-xs text-zinc-500">Configured via <code class="text-zinc-700 dark:text-zinc-300">config/shipping.php</code></span>
                </div>
            </div>

            @if ($activeDriver === 'shiprocket' || config('shipping.shiprocket.email'))
            <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-850 space-y-2">
                <div class="flex items-center justify-between">
                    <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">Shiprocket Wallet Balance</span>
                    <span class="inline-flex items-center rounded-md px-2 py-0.5 text-xs font-bold {{ ($this->shiprocketWalletBalance ?? 0) > 100 ? 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/50 dark:text-emerald-300' : 'bg-amber-100 text-amber-800 dark:bg-amber-900/50 dark:text-amber-300' }}">
                        ₹{{ number_format($this->shiprocketWalletBalance ?? 0, 2) }}
                    </span>
                </div>
                <div class="flex items-center justify-between text-xs text-zinc-500">
                    <span>Courier Selection Strategy:</span>
                    <span class="font-bold text-zinc-800 dark:text-zinc-200 uppercase">{{ config('shipping.shiprocket.courier_selection_rule', 'recommended') }}</span>
                </div>
            </div>
            @endif

            <!-- Safe Mode Status -->
            <div class="rounded-xl p-4 {{ config('shipping.safe_mode') ? 'bg-amber-50 border border-amber-200 dark:bg-amber-950/30 dark:border-amber-900' : 'bg-emerald-50 border border-emerald-200 dark:bg-emerald-950/30 dark:border-emerald-900' }}">
                <div class="flex items-center justify-between">
                    <div>
                        <span class="text-xs font-bold uppercase tracking-wider {{ config('shipping.safe_mode') ? 'text-amber-900 dark:text-amber-300' : 'text-emerald-900 dark:text-emerald-300' }}">
                            Safe Mode: {{ config('shipping.safe_mode') ? 'ACTIVE' : 'INACTIVE' }}
                        </span>
                        <p class="text-xs {{ config('shipping.safe_mode') ? 'text-amber-700 dark:text-amber-400' : 'text-emerald-700 dark:text-emerald-400' }} mt-0.5">
                            @if (config('shipping.safe_mode'))
                                Real package manifestations on Ekart production are blocked to protect testing accounts.
                            @else
                                Live package manifestation is active. Real orders will be dispatched.
                            @endif
                        </p>
                    </div>
                </div>
            </div>

            <!-- Test Connection Button -->
            @can('shipping.manage')
            <div class="pt-2 flex items-center gap-3">
                <button
                    type="button"
                    wire:click="testConnection"
                    wire:loading.attr="disabled"
                    class="rounded-xl bg-zinc-900 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-zinc-200 transition"
                >
                    Test Carrier API Connection
                </button>
            </div>
            @endcan
        </div>

        <!-- Webhook Status & Configuration -->
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
            <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                Webhook Status
            </h3>

            <div class="space-y-3">
                <div class="text-xs font-semibold text-zinc-500 uppercase tracking-wider">Ekart Webhook</div>
                @if ($this->webhookConfigured)
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-900 dark:bg-emerald-950/30">
                        <span class="text-xs font-bold text-emerald-900 dark:text-emerald-300">Ekart Webhook: Active</span>
                        <p class="text-xs text-emerald-700 dark:text-emerald-400 mt-1">
                            Endpoint: <code class="font-mono text-zinc-800 dark:text-zinc-200">{{ url('/webhooks/shipping/ekart') }}</code>
                        </p>
                    </div>
                @else
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                        <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">Ekart Webhooks: not configured — using polling</span>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                            Ekart webhook headers not configured; relying on 15m polling.
                        </p>
                    </div>
                @endif

                <div class="text-xs font-semibold text-zinc-500 uppercase tracking-wider pt-1">Shiprocket Webhook</div>
                @if ($this->shiprocketWebhookConfigured)
                    <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-3 dark:border-emerald-900 dark:bg-emerald-950/30">
                        <span class="text-xs font-bold text-emerald-900 dark:text-emerald-300">Shiprocket Webhook: Active</span>
                        <p class="text-xs text-emerald-700 dark:text-emerald-400 mt-1">
                            Endpoint: <code class="font-mono text-zinc-800 dark:text-zinc-200">{{ url('/webhooks/shipping/shiprocket') }}</code>
                        </p>
                    </div>
                @else
                    <div class="rounded-xl border border-zinc-200 bg-zinc-50 p-3 dark:border-zinc-800 dark:bg-zinc-800/40">
                        <span class="text-xs font-bold text-zinc-700 dark:text-zinc-300">Shiprocket Webhook: not configured — using polling</span>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-1">
                            Shiprocket webhook token not configured; relying on 15m polling.
                        </p>
                    </div>
                @endif
            </div>

            <div class="text-xs text-zinc-500 space-y-1">
                <p>Polling Schedule: <code class="text-zinc-700 dark:text-zinc-300">shipping:poll-tracking (every 15 min)</code></p>
                <p>COD Hard Limit: <strong>₹{{ number_format(config('shipping.cod_max_limit_inr', 49999)) }}</strong> per seller package</p>
                <p>Volumetric Divisor: <strong>{{ config('shipping.volumetric_divisor', 5000) }}</strong></p>
            </div>
        </div>

        <!-- Seller Warehouses Sync -->
        <div class="md:col-span-2 rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
            <div class="flex items-center justify-between border-b border-zinc-100 pb-3 dark:border-zinc-800">
                <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-400">
                    Seller Pickup Warehouse Sync
                </h3>
                @can('shipping.manage')
                <button
                    type="button"
                    wire:click="syncWarehouses"
                    wire:loading.attr="disabled"
                    class="rounded-xl bg-indigo-600 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white hover:bg-indigo-700 transition"
                >
                    Sync All Warehouses
                </button>
                @endcan
            </div>

            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4 text-center">
                <div class="rounded-xl bg-zinc-50 p-4 dark:bg-zinc-800/50">
                    <span class="text-xs text-zinc-500">Total Profiles</span>
                    <p class="text-xl font-extrabold text-zinc-900 dark:text-white mt-1">{{ $this->warehouseStats['total'] }}</p>
                </div>
                <div class="rounded-xl bg-emerald-50 p-4 dark:bg-emerald-950/30">
                    <span class="text-xs text-emerald-700 dark:text-emerald-400">Registered</span>
                    <p class="text-xl font-extrabold text-emerald-800 dark:text-emerald-300 mt-1">{{ $this->warehouseStats['registered'] }}</p>
                </div>
                <div class="rounded-xl bg-amber-50 p-4 dark:bg-amber-950/30">
                    <span class="text-xs text-amber-700 dark:text-amber-400">Pending</span>
                    <p class="text-xl font-extrabold text-amber-800 dark:text-amber-300 mt-1">{{ $this->warehouseStats['pending'] }}</p>
                </div>
                <div class="rounded-xl bg-red-50 p-4 dark:bg-red-950/30">
                    <span class="text-xs text-red-700 dark:text-red-400">Failed</span>
                    <p class="text-xl font-extrabold text-red-800 dark:text-red-300 mt-1">{{ $this->warehouseStats['failed'] }}</p>
                </div>
            </div>
        </div>
    </div>
</div>

