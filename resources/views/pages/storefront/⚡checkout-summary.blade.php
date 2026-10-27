<?php

namespace App\Livewire\Storefront;

use App\Checkout\Models\CheckoutIntent;
use App\Checkout\Services\CheckoutIntentService;
use App\Models\CustomerAddress;
use App\Orders\DTOs\PlaceOrderData;
use App\Orders\Services\OrderService;
use App\Shipping\Contracts\DeliveryServiceabilityLookupInterface;
use App\Shipping\DTOs\CodValidationResultDto;
use App\Support\CustomerAddressRules;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts.storefront')] class extends Component {
    public const STATES = CustomerAddressRules::STATES;

    public string $intentId;

    public int $currentStep = 2; // 1: Login (done), 2: Address, 3: Summary, 4: Payment

    public ?int $selectedAddressId = null;

    public bool $showAddressForm = false;

    // Address form inputs
    public string $addressName = '';

    public string $addressPhone = '';

    public string $addressPincode = '';

    public string $addressLocality = '';

    public string $addressLine = '';

    public string $addressCity = '';

    public string $addressState = 'Maharashtra';

    public string $addressLandmark = '';

    public string $addressAlternatePhone = '';

    public string $addressType = 'home';

    public bool $addressIsDefault = false;

    public string $couponInput = '';

    public function mount(string $intent): void
    {
        $this->intentId = $intent;

        $intentModel = $this->intent;
        if (! $intentModel) {
            abort(404, 'Checkout intent not found.');
        }

        if ($intentModel->user_id !== auth()->id()) {
            abort(403, 'Unauthorized checkout access.');
        }

        if ($intentModel->status === 'completed') {
            $order = \App\Orders\Models\Order::where('checkout_intent_id', $intentModel->id)->first();
            if ($order) {
                $this->redirect(route('orders.show', ['order' => $order->order_number]));

                return;
            }
        }

        if ($intentModel->isExpired()) {
            session()->flash('cart_error', 'Your checkout session has expired. Please review your items and try again.');
            $this->redirect($intentModel->getExpiredRedirectUrl());

            return;
        }

        // Pre-select default address if available
        $defaultAddr = auth()->user()->addresses()->where('is_default', true)->first()
            ?? auth()->user()->addresses()->latest()->first();

        if ($defaultAddr) {
            $this->selectedAddressId = $defaultAddr->id;
        } else {
            $this->showAddressForm = true;
        }
    }

    #[Computed]
    public function intent(): ?CheckoutIntent
    {
        return CheckoutIntent::find($this->intentId);
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function evaluation(): array
    {
        $intentModel = $this->intent;
        if (! $intentModel) {
            return [];
        }

        return app(CheckoutIntentService::class)->evaluateIntent($intentModel);
    }

    #[Computed]
    public function addresses(): \Illuminate\Database\Eloquent\Collection
    {
        return auth()->user()->addresses()->latest()->get();
    }

    #[Computed]
    public function codValidation(): CodValidationResultDto
    {
        if (! $this->selectedAddressId) {
            return new CodValidationResultDto(isAvailable: true);
        }

        $addr = CustomerAddress::find($this->selectedAddressId);
        if (! $addr || empty($addr->pincode)) {
            return new CodValidationResultDto(isAvailable: true);
        }

        $eval = $this->evaluation;
        $activeLines = $eval['active_lines'] ?? [];
        if (empty($activeLines)) {
            return new CodValidationResultDto(isAvailable: true);
        }

        $sellerPackages = [];
        foreach ($activeLines as $line) {
            $sellerId = (int) ($line['seller_profile_id'] ?? 0);
            $sellerName = (string) ($line['seller_name'] ?? 'Seller');
            $subtotal = (int) ($line['subtotal'] ?? 0);

            if (! isset($sellerPackages[$sellerId])) {
                $sellerPackages[$sellerId] = [
                    'seller_profile_id' => $sellerId,
                    'seller_store_name' => $sellerName,
                    'cod_amount_paise' => 0,
                    'delivery_pincode' => (string) $addr->pincode,
                ];
            }
            $sellerPackages[$sellerId]['cod_amount_paise'] += $subtotal;
        }

        return app(DeliveryServiceabilityLookupInterface::class)->validatePackageCodLimits(array_values($sellerPackages));
    }

    public function selectAddress(int $addressId): void
    {
        $this->selectedAddressId = $addressId;
    }

    public function deliverHere(): void
    {
        if (! $this->selectedAddressId || CustomerAddress::where('id', $this->selectedAddressId)->doesntExist()) {
            $this->selectedAddressId = null;
            session()->flash('checkout_error', 'Please select or add a delivery address to continue.');

            return;
        }

        $this->currentStep = 3; // Advance to summary & payment
    }

    public function saveNewAddress(): void
    {
        $this->validate(
            CustomerAddressRules::rules('address'),
            CustomerAddressRules::messages('address')
        );

        if ($this->addressIsDefault) {
            auth()->user()->addresses()->update(['is_default' => false]);
        }

        $addr = auth()->user()->addresses()->create([
            'name' => $this->addressName,
            'phone' => $this->addressPhone,
            'pincode' => $this->addressPincode,
            'locality' => $this->addressLocality,
            'address_line' => $this->addressLine,
            'city' => $this->addressCity,
            'state' => $this->addressState,
            'landmark' => $this->addressLandmark ?: null,
            'alternate_phone' => $this->addressAlternatePhone ?: null,
            'type' => $this->addressType,
            'is_default' => $this->addressIsDefault,
        ]);

        $this->selectedAddressId = $addr->id;
        $this->showAddressForm = false;
        $this->currentStep = 3;
        session()->flash('checkout_success', 'Delivery address saved.');
    }

    public function increaseQty(int $listingId, CheckoutIntentService $service): void
    {
        $intentModel = $this->intent;
        if (! $intentModel || $intentModel->isExpired()) {
            session()->flash('cart_error', 'This checkout session has expired.');
            $this->redirect(route('cart'));

            return;
        }

        $line = collect($intentModel->lines)->firstWhere('listing_id', $listingId);
        $currentQty = $line ? (int) $line['qty'] : 1;

        $service->updateLineQty($intentModel, $listingId, $currentQty + 1);
        unset($this->intent, $this->evaluation);
    }

    public function decreaseQty(int $listingId, CheckoutIntentService $service): void
    {
        $intentModel = $this->intent;
        if (! $intentModel || $intentModel->isExpired()) {
            session()->flash('cart_error', 'This checkout session has expired.');
            $this->redirect(route('cart'));

            return;
        }

        $line = collect($intentModel->lines)->firstWhere('listing_id', $listingId);
        $currentQty = $line ? (int) $line['qty'] : 1;

        if ($currentQty > 1) {
            $service->updateLineQty($intentModel, $listingId, $currentQty - 1);
            unset($this->intent, $this->evaluation);
        }
    }

    public function removeLine(int $listingId, CheckoutIntentService $service): void
    {
        $intentModel = $this->intent;
        if (! $intentModel || $intentModel->isExpired()) {
            session()->flash('cart_error', 'This checkout session has expired.');
            $this->redirect(route('cart'));

            return;
        }

        $service->removeLine($intentModel, $listingId);
        unset($this->intent, $this->evaluation);

        if (empty($intentModel->fresh()->lines)) {
            session()->flash('cart_error', 'All items were removed from this checkout session.');
            $this->redirect(route('cart'));
        }
    }

    public function applyCoupon(CheckoutIntentService $service): void
    {
        if (! config('coupons.enabled', false)) {
            session()->flash('coupon_error', 'Coupons are currently disabled.');

            return;
        }

        $this->couponInput = strtoupper(trim($this->couponInput));
        if ($this->couponInput === '') {
            session()->flash('coupon_error', 'Please enter a coupon code.');

            return;
        }

        $intentModel = $this->intent;
        if (! $intentModel || $intentModel->isExpired()) {
            session()->flash('checkout_error', 'This checkout session has expired.');

            return;
        }

        $evaluation = $service->applyCoupon($intentModel, $this->couponInput);
        unset($this->intent, $this->evaluation);

        if ($evaluation->isValid) {
            session()->flash('coupon_success', "Coupon '{$evaluation->couponCode}' applied successfully!");
            $this->couponInput = '';
        } else {
            session()->flash('coupon_error', $evaluation->message ?? 'Invalid coupon code.');
        }
    }

    public function removeCoupon(CheckoutIntentService $service): void
    {
        $intentModel = $this->intent;
        if ($intentModel) {
            $service->removeCoupon($intentModel);
            unset($this->intent, $this->evaluation);
            session()->flash('coupon_info', 'Coupon removed.');
        }
    }

    public function confirmOrder(OrderService $orderService): void
    {
        if (! $this->selectedAddressId || CustomerAddress::where('id', $this->selectedAddressId)->doesntExist()) {
            $this->selectedAddressId = null;
            $this->currentStep = 2;
            session()->flash('checkout_error', 'Please select a delivery address before confirming your order.');

            return;
        }

        $codCheck = $this->codValidation;
        if (! $codCheck->isAvailable) {
            session()->flash('checkout_error', 'Cash on Delivery is unavailable: '.$codCheck->reasonMessage());

            return;
        }

        $eval = $this->evaluation;
        $activeLines = $eval['active_lines'] ?? [];

        if (empty($activeLines)) {
            session()->flash('checkout_error', 'There are no purchasable items in this order.');

            return;
        }

        $linesData = array_map(fn ($line) => [
            'listing_id' => (int) $line['listing_id'],
            'qty' => (int) $line['qty'],
        ], $activeLines);

        $placeData = new PlaceOrderData(
            userId: auth()->id(),
            addressId: $this->selectedAddressId,
            paymentMethod: 'cash_on_delivery',
            lines: $linesData,
            expectedTotal: (int) ($eval['summary']['payable_total'] ?? 0),
            idempotencyKey: $this->intent->id,
            source: $this->intent->source ?? 'cart',
            couponCode: $this->intent->coupon_code,
        );

        try {
            $order = $orderService->place($placeData);

            // Mark checkout intent completed
            $this->intent->update(['status' => 'completed']);

            $this->redirect(route('orders.success', ['order' => $order->order_number]));
        } catch (\DomainException $e) {
            session()->flash('checkout_error', $e->getMessage());
            unset($this->evaluation);
        }
    }
}; ?>

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8 pb-24 sm:pb-8">
    <!-- Breadcrumb -->
    <nav class="mb-6 flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400">
        <a href="{{ route('products.index') }}" class="hover:text-zinc-800 dark:hover:text-zinc-200" wire:navigate>Home</a>
        <span>/</span>
        <a href="{{ route('cart') }}" class="hover:text-zinc-800 dark:hover:text-zinc-200" wire:navigate>Cart</a>
        <span>/</span>
        <span class="truncate font-medium text-zinc-800 dark:text-zinc-200">Checkout</span>
    </nav>

    <!-- Global Alerts -->
    @if (session()->has('checkout_error'))
        <div class="mb-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/50 dark:text-red-300">
            {{ session('checkout_error') }}
        </div>
    @endif
    @if (session()->has('checkout_success'))
        <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/50 dark:text-emerald-300">
            {{ session('checkout_success') }}
        </div>
    @endif

    @php
        $eval = $this->evaluation;
        $activeLines = $eval['active_lines'] ?? [];
        $unavailableLines = $eval['unavailable_lines'] ?? [];
        $priceChanges = $eval['price_changes'] ?? [];
        $summary = $eval['summary'] ?? [];
    @endphp

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-3">
        <!-- Left: 4 Flipkart Steps -->
        <div class="lg:col-span-2 space-y-4">
            <!-- Step 1: Login / Account -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-emerald-500 text-xs font-bold text-white">✓</span>
                        <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            1. Login Account
                        </h2>
                    </div>
                </div>
                <div class="mt-2 pl-9 text-sm text-zinc-700 dark:text-zinc-300">
                    <span class="font-bold text-zinc-900 dark:text-white">{{ auth()->user()->name }}</span>
                    <span class="text-zinc-500 dark:text-zinc-400">({{ auth()->user()->email }})</span>
                </div>
            </div>

            <!-- Step 2: Delivery Address -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full {{ $this->selectedAddressId && $this->currentStep > 2 ? 'bg-emerald-500 text-white' : 'bg-amber-500 text-white' }} text-xs font-bold">
                            {{ $this->selectedAddressId && $this->currentStep > 2 ? '✓' : '2' }}
                        </span>
                        <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            2. Delivery Address
                        </h2>
                    </div>
                    @if ($this->selectedAddressId && $this->currentStep > 2)
                        <button
                            type="button"
                            wire:click="$set('currentStep', 2)"
                            class="rounded-lg border border-zinc-300 px-3 py-1 text-xs font-bold uppercase tracking-wider text-amber-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-amber-400"
                        >
                            Change
                        </button>
                    @endif
                </div>

                <!-- If step is closed / completed, show snapshot preview -->
                @if ($this->currentStep > 2 && $this->selectedAddressId)
                    @php $chosen = $this->addresses->firstWhere('id', $this->selectedAddressId); @endphp
                    @if ($chosen)
                        <div class="mt-3 pl-9 text-sm text-zinc-700 dark:text-zinc-300">
                            <span class="font-bold text-zinc-900 dark:text-white">{{ $chosen->name }}</span>
                            <span class="ml-2 inline-flex items-center rounded-md bg-zinc-100 px-2 py-0.5 text-xs font-semibold text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 uppercase">{{ $chosen->type }}</span>
                            <p class="mt-1 text-zinc-600 dark:text-zinc-400">{{ $chosen->formatted() }}</p>
                            <p class="mt-0.5 font-medium text-zinc-800 dark:text-zinc-200">Phone: {{ $chosen->phone }}</p>
                        </div>
                    @endif
                @else
                    <!-- Active Step 2: Choose Address or Add New -->
                    <div class="mt-5 space-y-4">
                        @if ($this->addresses->isNotEmpty() && ! $this->showAddressForm)
                            <div class="space-y-3">
                                @foreach ($this->addresses as $addr)
                                    <label class="block cursor-pointer rounded-xl border p-4 transition {{ $this->selectedAddressId === $addr->id ? 'border-amber-500 bg-amber-50/50 ring-2 ring-amber-500/20 dark:border-amber-500/80 dark:bg-amber-950/20' : 'border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900' }}">
                                        <div class="flex items-start gap-3">
                                            <input
                                                type="radio"
                                                name="selected_address"
                                                value="{{ $addr->id }}"
                                                wire:click="selectAddress({{ $addr->id }})"
                                                {{ $this->selectedAddressId === $addr->id ? 'checked' : '' }}
                                                class="mt-1 h-4 w-4 text-amber-600 focus:ring-amber-500"
                                            />
                                            <div class="flex-1 text-sm">
                                                <div class="flex items-center gap-2">
                                                    <span class="font-bold text-zinc-900 dark:text-white">{{ $addr->name }}</span>
                                                    <span class="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-bold uppercase text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">{{ $addr->type }}</span>
                                                    <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $addr->phone }}</span>
                                                </div>
                                                <p class="mt-1 text-zinc-600 dark:text-zinc-400">{{ $addr->formatted() }}</p>

                                                @if ($this->selectedAddressId === $addr->id)
                                                    <div class="mt-3">
                                                        <button
                                                            type="button"
                                                            wire:click="deliverHere"
                                                            class="rounded-xl bg-orange-600 px-5 py-2 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-orange-700 transition"
                                                        >
                                                            Deliver Here
                                                        </button>
                                                    </div>
                                                @endif
                                            </div>
                                        </div>
                                    </label>
                                @endforeach
                            </div>

                            <div class="pt-2">
                                <button
                                    type="button"
                                    wire:click="$set('showAddressForm', true)"
                                    class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-amber-600 hover:text-amber-700 dark:text-amber-400"
                                >
                                    <span>+ Add a new delivery address</span>
                                </button>
                            </div>
                        @endif

                        <!-- Add Address Form -->
                        @if ($this->showAddressForm || $this->addresses->isEmpty())
                            <div class="rounded-xl border border-zinc-200 bg-zinc-50/50 p-5 dark:border-zinc-800 dark:bg-zinc-800/40">
                                <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300 mb-4">
                                    Add New Address
                                </h3>

                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 text-sm">
                                    <div>
                                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Full Name *</label>
                                        <input type="text" wire:model="addressName" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                                        @error('addressName') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>

                                    <div>
                                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">10-Digit Mobile *</label>
                                        <input type="text" wire:model="addressPhone" placeholder="9876543210" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                                        @error('addressPhone') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>

                                    <div>
                                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Pincode *</label>
                                        <input type="text" wire:model="addressPincode" placeholder="400001" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                                        @error('addressPincode') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>

                                    <div>
                                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Locality / Area *</label>
                                        <input type="text" wire:model="addressLocality" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                                        @error('addressLocality') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Address (Flat, Building, Street) *</label>
                                        <textarea wire:model="addressLine" rows="2" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900"></textarea>
                                        @error('addressLine') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>

                                    <div>
                                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">City / District *</label>
                                        <input type="text" wire:model="addressCity" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                                        @error('addressCity') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>

                                    <div>
                                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">State *</label>
                                        <select wire:model="addressState" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900">
                                            @foreach (self::STATES as $st)
                                                <option value="{{ $st }}">{{ $st }}</option>
                                            @endforeach
                                        </select>
                                        @error('addressState') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>

                                    <div>
                                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Landmark (Optional)</label>
                                        <input type="text" wire:model="addressLandmark" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                                    </div>

                                    <div>
                                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Alternate Phone (Optional)</label>
                                        <input type="text" wire:model="addressAlternatePhone" class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900" />
                                        @error('addressAlternatePhone') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                                    </div>

                                    <div class="sm:col-span-2 flex items-center gap-6 pt-2">
                                        <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Address Type:</span>
                                        <label class="inline-flex items-center gap-1.5 text-xs">
                                            <input type="radio" value="home" wire:model="addressType" class="text-amber-600" /> Home
                                        </label>
                                        <label class="inline-flex items-center gap-1.5 text-xs">
                                            <input type="radio" value="work" wire:model="addressType" class="text-amber-600" /> Work
                                        </label>
                                    </div>
                                </div>

                                <div class="mt-5 flex items-center gap-3">
                                    <button
                                        type="button"
                                        wire:click="saveNewAddress"
                                        class="rounded-xl bg-amber-500 px-6 py-2.5 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-amber-600 transition"
                                    >
                                        Save and Deliver Here
                                    </button>
                                    @if ($this->addresses->isNotEmpty())
                                        <button
                                            type="button"
                                            wire:click="$set('showAddressForm', false)"
                                            class="text-xs font-semibold text-zinc-500 hover:text-zinc-800 dark:text-zinc-400"
                                        >
                                            Cancel
                                        </button>
                                    @endif
                                </div>
                            </div>
                        @endif
                    </div>
                @endif
            </div>

            <!-- Step 3: Order Summary -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <span class="flex h-6 w-6 items-center justify-center rounded-full bg-amber-500 text-xs font-bold text-white">
                            3
                        </span>
                        <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                            3. Order Summary ({{ count($activeLines) }} items)
                        </h2>
                    </div>
                </div>

                @if (! empty($priceChanges))
                    <div class="mt-4 rounded-xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700/60 dark:bg-amber-950/40 dark:text-amber-200">
                        <p class="font-semibold">Price change notice</p>
                        <p class="text-xs text-amber-800 dark:text-amber-300">Live prices have updated. Review and confirm your items below.</p>
                    </div>
                @endif

                <div class="mt-5 divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($activeLines as $line)
                        @php
                            $unitPrice = (int) $line['unit_price'];
                            $mrp = (int) $line['mrp'];
                            $discountPct = $mrp > $unitPrice ? (int) round((($mrp - $unitPrice) / $mrp) * 100) : 0;
                        @endphp
                        <div class="py-5 flex flex-col sm:flex-row gap-5">
                            <div class="h-20 w-20 flex-shrink-0 overflow-hidden rounded-xl border border-zinc-200 bg-white p-2 dark:border-zinc-800 dark:bg-zinc-800">
                                <img
                                    src="{{ $line['image'] ?: asset('storage/products/placeholder.svg') }}"
                                    alt="{{ $line['name'] }}"
                                    class="h-full w-full object-contain"
                                />
                            </div>

                            <div class="flex-1 space-y-2">
                                <h3 class="font-semibold text-zinc-900 dark:text-white text-sm">
                                    {{ $line['name'] }}
                                </h3>
                                @if (! empty($line['seller_name']))
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                                        Sold by <strong class="text-zinc-700 dark:text-zinc-300">{{ $line['seller_name'] }}</strong>
                                    </p>
                                @endif

                                <div class="flex items-baseline gap-2.5">
                                    <span class="text-base font-bold text-zinc-900 dark:text-white">
                                        ₹{{ number_format($unitPrice / 100) }}
                                    </span>
                                    @if ($mrp > $unitPrice)
                                        <span class="text-xs text-zinc-400 line-through">
                                            ₹{{ number_format($mrp / 100) }}
                                        </span>
                                        <span class="text-xs font-bold text-emerald-600 dark:text-emerald-400">
                                            {{ $discountPct }}% Off
                                        </span>
                                    @endif
                                </div>

                                <!-- Quantity Stepper & Remove Action -->
                                <div class="pt-2 flex items-center gap-6">
                                    <div class="inline-flex items-center rounded-lg border border-zinc-200 bg-white dark:border-zinc-700 dark:bg-zinc-800">
                                        <button
                                            type="button"
                                            wire:click="decreaseQty({{ $line['listing_id'] }})"
                                            wire:loading.attr="disabled"
                                            {{ $line['qty'] <= 1 ? 'disabled' : '' }}
                                            class="h-7 w-7 flex items-center justify-center text-zinc-600 hover:bg-zinc-100 disabled:opacity-30 disabled:cursor-not-allowed dark:text-zinc-300 rounded-l-lg transition"
                                        >
                                            -
                                        </button>
                                        <span class="w-8 text-center text-xs font-bold text-zinc-900 dark:text-white">
                                            {{ $line['qty'] }}
                                        </span>
                                        <button
                                            type="button"
                                            wire:click="increaseQty({{ $line['listing_id'] }})"
                                            wire:loading.attr="disabled"
                                            {{ $line['qty'] >= $line['max_qty'] ? 'disabled' : '' }}
                                            class="h-7 w-7 flex items-center justify-center text-zinc-600 hover:bg-zinc-100 disabled:opacity-30 disabled:cursor-not-allowed dark:text-zinc-300 rounded-r-lg transition"
                                        >
                                            +
                                        </button>
                                    </div>

                                    <button
                                        type="button"
                                        wire:click="removeLine({{ $line['listing_id'] }})"
                                        wire:confirm="Remove this item from your order?"
                                        wire:loading.attr="disabled"
                                        class="text-xs font-bold uppercase tracking-wider text-zinc-500 hover:text-red-600 transition"
                                    >
                                        Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            <!-- Step 4: Payment Options (Cash on Delivery) -->
            @php
                $codStatus = $this->codValidation;
            @endphp
            <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center gap-3 mb-4">
                    <span class="flex h-6 w-6 items-center justify-center rounded-full bg-amber-500 text-xs font-bold text-white">
                        4
                    </span>
                    <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">
                        4. Payment Method
                    </h2>
                </div>

                @if ($codStatus->isAvailable)
                    <div class="rounded-xl border border-amber-500 bg-amber-50/40 p-4 dark:border-amber-600/70 dark:bg-amber-950/20">
                        <label class="flex items-center gap-3 cursor-pointer">
                            <input type="radio" checked readonly class="h-4 w-4 text-amber-600 focus:ring-amber-500" />
                            <div>
                                <span class="font-bold text-sm text-zinc-900 dark:text-white">Cash on Delivery</span>
                                <p class="text-xs text-zinc-600 dark:text-zinc-400 mt-0.5">Pay with cash or UPI to the delivery courier when your order arrives.</p>
                            </div>
                        </label>
                    </div>

                    <div class="mt-6">
                        <button
                            type="button"
                            wire:click="confirmOrder"
                            wire:loading.attr="disabled"
                            class="w-full sm:w-auto rounded-xl bg-orange-600 px-8 py-3.5 text-center text-sm font-bold uppercase tracking-wider text-white shadow-sm hover:bg-orange-700 active:scale-[0.99] transition flex items-center justify-center gap-2"
                        >
                            <span>CONFIRM ORDER (Cash on Delivery)</span>
                        </button>
                    </div>
                @else
                    <div class="rounded-xl border border-red-200 bg-red-50 p-4 dark:border-red-900/50 dark:bg-red-950/30">
                        <div class="flex items-start gap-3">
                            <span class="text-red-600 font-bold">✕</span>
                            <div>
                                <h3 class="text-sm font-bold text-red-900 dark:text-red-300">Cash on Delivery Unavailable</h3>
                                <p class="text-xs text-red-700 dark:text-red-400 mt-1">{{ $codStatus->reasonMessage() }}</p>
                                <p class="text-xs text-zinc-500 dark:text-zinc-400 mt-2">Please select another delivery address or reduce package quantity.</p>
                            </div>
                        </div>
                    </div>

                    <div class="mt-6">
                        <button
                            type="button"
                            disabled
                            class="w-full sm:w-auto rounded-xl bg-zinc-300 dark:bg-zinc-800 px-8 py-3.5 text-center text-sm font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 cursor-not-allowed"
                        >
                            <span>COD Unavailable</span>
                        </button>
                    </div>
                @endif
            </div>
        </div>

        <!-- Right: Sticky Price Details -->
        <div class="lg:col-span-1 space-y-4">
            @if (config('coupons.enabled', false))
            <!-- Coupon / Promo Code Card -->
            <div class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-3 flex items-center justify-between">
                    <span>Apply Coupon</span>
                    <svg class="h-4 w-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5c.512 0 1.024.195 1.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.994 1.994 0 013 12V7a4 4 0 014-4z" />
                    </svg>
                </h3>

                @if ($eval['coupon_revoked'] ?? false)
                    <div class="mb-3 rounded-xl bg-amber-50 p-3 text-xs text-amber-800 dark:bg-amber-950/40 dark:text-amber-300 border border-amber-200 dark:border-amber-800">
                        {{ $eval['coupon_revoked_reason'] }}
                    </div>
                @endif

                @if (session()->has('coupon_error'))
                    <div class="mb-3 rounded-xl bg-red-50 p-3 text-xs text-red-700 dark:bg-red-950/40 dark:text-red-300 border border-red-200 dark:border-red-900/50">
                        {{ session('coupon_error') }}
                    </div>
                @endif

                @if (session()->has('coupon_success'))
                    <div class="mb-3 rounded-xl bg-emerald-50 p-3 text-xs text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-800">
                        {{ session('coupon_success') }}
                    </div>
                @endif

                @if (session()->has('coupon_info'))
                    <div class="mb-3 rounded-xl bg-zinc-100 p-3 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                        {{ session('coupon_info') }}
                    </div>
                @endif

                @if (! empty($summary['coupon']))
                    <div class="flex items-center justify-between rounded-xl bg-emerald-50/60 p-3 border border-emerald-200 dark:border-emerald-800/60 dark:bg-emerald-950/20">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="font-mono text-xs font-bold uppercase tracking-wider text-emerald-700 dark:text-emerald-400 bg-emerald-100 dark:bg-emerald-900/50 px-2 py-0.5 rounded">
                                    {{ $summary['coupon']['code'] }}
                                </span>
                                <span class="text-xs font-semibold text-emerald-600 dark:text-emerald-400">
                                    −₹{{ number_format($summary['coupon']['discount'] / 100) }}
                                </span>
                            </div>
                            <p class="text-[11px] text-emerald-600 dark:text-emerald-400 mt-1">Applied</p>
                        </div>
                        <button
                            type="button"
                            wire:click="removeCoupon"
                            class="text-xs font-semibold text-red-600 hover:text-red-700 dark:text-red-400 hover:underline cursor-pointer"
                        >
                            Remove
                        </button>
                    </div>
                @else
                    <form wire:submit.prevent="applyCoupon" class="flex gap-2">
                        <input
                            type="text"
                            wire:model="couponInput"
                            placeholder="Enter coupon code"
                            class="flex-1 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2 text-xs uppercase tracking-wider text-zinc-900 placeholder-zinc-400 focus:border-emerald-500 focus:bg-white focus:outline-none dark:border-zinc-800 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500"
                        />
                        <button
                            type="submit"
                            wire:loading.attr="disabled"
                            class="rounded-xl bg-zinc-900 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white hover:bg-zinc-800 dark:bg-zinc-100 dark:text-zinc-900 dark:hover:bg-white transition cursor-pointer"
                        >
                            Apply
                        </button>
                    </form>
                @endif
            </div>
            @endif

            <!-- Sticky Price Details -->
            <div class="sticky top-20 rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-5">
                <h2 class="text-xs font-bold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 border-b border-zinc-100 dark:border-zinc-800 pb-3">
                    Price Details
                </h2>

                <div class="space-y-3 text-sm">
                    <div class="flex justify-between text-zinc-700 dark:text-zinc-300">
                        <span>Price ({{ $summary['items_count'] ?? 0 }} items)</span>
                        <span>₹{{ number_format(($summary['mrp_total'] ?? 0) / 100) }}</span>
                    </div>

                    <div class="flex justify-between text-emerald-600 dark:text-emerald-400">
                        <span>Catalog Savings</span>
                        <span>−₹{{ number_format(($summary['discount_total'] ?? 0) / 100) }}</span>
                    </div>

                    @if (($summary['coupon_discount'] ?? 0) > 0)
                        <div class="flex justify-between text-emerald-600 dark:text-emerald-400 font-medium">
                            <span>Coupon Discount ({{ $summary['coupon']['code'] ?? '' }})</span>
                            <span>−₹{{ number_format($summary['coupon_discount'] / 100) }}</span>
                        </div>
                    @endif

                    <div class="flex justify-between text-zinc-700 dark:text-zinc-300">
                        <span>Delivery Charges</span>
                        <span class="text-emerald-600 dark:text-emerald-400 font-medium">Free</span>
                    </div>

                    <div class="border-t border-dashed border-zinc-200 pt-3 dark:border-zinc-800 flex justify-between text-base font-bold text-zinc-900 dark:text-white">
                        <span>Total Payable</span>
                        <span>₹{{ number_format(($summary['payable_total'] ?? 0) / 100) }}</span>
                    </div>
                </div>

                @if (($summary['savings'] ?? 0) > 0)
                    <div class="rounded-xl bg-emerald-50 p-3 text-xs font-semibold text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300">
                        You will save ₹{{ number_format($summary['savings'] / 100) }} on this order
                    </div>
                @endif
            </div>
        </div>
    </div>
</div>
