<?php

namespace App\Livewire\Storefront;

use App\Models\CustomerAddress;
use App\Support\CustomerAddressRules;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.app')] #[Title('My Addresses')] class extends Component {
    public const STATES = CustomerAddressRules::STATES;

    public bool $showForm = false;

    public ?int $editingAddressId = null;

    public string $name = '';

    public string $phone = '';

    public string $pincode = '';

    public string $locality = '';

    public string $address_line = '';

    public string $city = '';

    public string $state = 'Maharashtra';

    public string $landmark = '';

    public string $alternate_phone = '';

    public string $type = 'home';

    public bool $is_default = false;

    #[Computed]
    public function addresses()
    {
        return auth()->user()->addresses()->latest()->get();
    }

    public function openCreateModal(): void
    {
        $this->reset([
            'editingAddressId',
            'name',
            'phone',
            'pincode',
            'locality',
            'address_line',
            'city',
            'landmark',
            'alternate_phone',
        ]);

        $this->state = 'Maharashtra';
        $this->type = 'home';
        $this->is_default = auth()->user()->addresses()->count() === 0;
        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function openEditModal(int $id): void
    {
        $address = CustomerAddress::findOrFail($id);
        $this->authorize('update', $address);

        $this->editingAddressId = $address->id;
        $this->name = $address->name;
        $this->phone = $address->phone;
        $this->pincode = $address->pincode;
        $this->locality = $address->locality;
        $this->address_line = $address->address_line;
        $this->city = $address->city;
        $this->state = $address->state;
        $this->landmark = (string) ($address->landmark ?? '');
        $this->alternate_phone = (string) ($address->alternate_phone ?? '');
        $this->type = $address->type;
        $this->is_default = (bool) $address->is_default;

        $this->showForm = true;
        $this->resetErrorBag();
    }

    public function cancelForm(): void
    {
        $this->showForm = false;
        $this->editingAddressId = null;
        $this->resetErrorBag();
    }

    public function saveAddress(): void
    {
        $this->validate(
            CustomerAddressRules::rules(),
            CustomerAddressRules::messages()
        );

        $user = auth()->user();

        if ($this->editingAddressId) {
            $address = CustomerAddress::findOrFail($this->editingAddressId);
            $this->authorize('update', $address);

            if ($this->is_default) {
                $user->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
            }

            $address->update([
                'name' => $this->name,
                'phone' => $this->phone,
                'pincode' => $this->pincode,
                'locality' => $this->locality,
                'address_line' => $this->address_line,
                'city' => $this->city,
                'state' => $this->state,
                'landmark' => $this->landmark ?: null,
                'alternate_phone' => $this->alternate_phone ?: null,
                'type' => $this->type,
                'is_default' => $this->is_default,
            ]);

            session()->flash('address_success', 'Address updated successfully.');
        } else {
            $hasNoAddresses = $user->addresses()->count() === 0;
            if ($this->is_default || $hasNoAddresses) {
                $this->is_default = true;
                $user->addresses()->update(['is_default' => false]);
            }

            $user->addresses()->create([
                'name' => $this->name,
                'phone' => $this->phone,
                'pincode' => $this->pincode,
                'locality' => $this->locality,
                'address_line' => $this->address_line,
                'city' => $this->city,
                'state' => $this->state,
                'landmark' => $this->landmark ?: null,
                'alternate_phone' => $this->alternate_phone ?: null,
                'type' => $this->type,
                'is_default' => $this->is_default,
            ]);

            session()->flash('address_success', 'Address added successfully.');
        }

        $this->showForm = false;
        $this->editingAddressId = null;
        unset($this->addresses);
    }

    public function setDefault(int $id): void
    {
        $address = CustomerAddress::findOrFail($id);
        $this->authorize('update', $address);

        auth()->user()->addresses()->where('id', '!=', $address->id)->update(['is_default' => false]);
        $address->update(['is_default' => true]);

        session()->flash('address_success', 'Default address updated.');
        unset($this->addresses);
    }

    public function deleteAddress(int $id): void
    {
        $address = CustomerAddress::findOrFail($id);
        $this->authorize('delete', $address);

        $wasDefault = $address->is_default;
        $address->delete();

        if ($wasDefault) {
            auth()->user()->addresses()->latest()->first()?->update(['is_default' => true]);
        }

        session()->flash('address_success', 'Address deleted successfully.');
        unset($this->addresses);
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
        <div>
            <h1 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-white sm:text-2xl">
                Manage Addresses
            </h1>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                Add, edit, or remove shipping addresses for your orders.
            </p>
        </div>

        @if (! $showForm)
            <div>
                <button
                    type="button"
                    wire:click="openCreateModal"
                    class="inline-flex items-center gap-1.5 rounded-xl bg-amber-500 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-amber-600 transition"
                >
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" />
                    </svg>
                    <span>+ Add New Address</span>
                </button>
            </div>
        @endif
    </div>

    <!-- Flash Alert -->
    @if (session()->has('address_success'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-xs font-semibold text-emerald-800 dark:border-emerald-900/50 dark:bg-emerald-950/50 dark:text-emerald-300">
            {{ session('address_success') }}
        </div>
    @endif

    <!-- Form Section (Add / Edit) -->
    @if ($showForm)
        <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-center justify-between border-b border-zinc-100 pb-4 dark:border-zinc-800">
                <h3 class="text-sm font-bold uppercase tracking-wider text-zinc-800 dark:text-zinc-200">
                    {{ $editingAddressId ? 'Edit Address' : 'Add New Address' }}
                </h3>
                <button
                    type="button"
                    wire:click="cancelForm"
                    class="text-xs font-semibold text-zinc-500 hover:text-zinc-800 dark:text-zinc-400"
                >
                    Cancel
                </button>
            </div>

            <form wire:submit="saveAddress" class="mt-5 space-y-4">
                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Full Name *</label>
                        <input
                            type="text"
                            wire:model="name"
                            placeholder="John Doe"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        />
                        @error('name') <span class="text-xs text-red-600 dark:text-red-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">10-Digit Mobile Number *</label>
                        <input
                            type="text"
                            wire:model="phone"
                            placeholder="9876543210"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        />
                        @error('phone') <span class="text-xs text-red-600 dark:text-red-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">6-Digit PIN Code *</label>
                        <input
                            type="text"
                            wire:model="pincode"
                            placeholder="400001"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        />
                        @error('pincode') <span class="text-xs text-red-600 dark:text-red-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Locality / Area *</label>
                        <input
                            type="text"
                            wire:model="locality"
                            placeholder="e.g. Bandra West"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        />
                        @error('locality') <span class="text-xs text-red-600 dark:text-red-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div class="sm:col-span-2">
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Address (Flat, Building, Street) *</label>
                        <textarea
                            wire:model="address_line"
                            rows="2"
                            placeholder="Flat 101, Sunshine Heights, Linking Road"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        ></textarea>
                        @error('address_line') <span class="text-xs text-red-600 dark:text-red-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">City / District / Town *</label>
                        <input
                            type="text"
                            wire:model="city"
                            placeholder="Mumbai"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        />
                        @error('city') <span class="text-xs text-red-600 dark:text-red-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">State *</label>
                        <select
                            wire:model="state"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        >
                            @foreach (self::STATES as $st)
                                <option value="{{ $st }}">{{ $st }}</option>
                            @endforeach
                        </select>
                        @error('state') <span class="text-xs text-red-600 dark:text-red-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Landmark (Optional)</label>
                        <input
                            type="text"
                            wire:model="landmark"
                            placeholder="Near City Mall"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        />
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300 mb-1">Alternate Phone (Optional)</label>
                        <input
                            type="text"
                            wire:model="alternate_phone"
                            placeholder="Optional 10-digit number"
                            class="w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-amber-500 focus:ring-1 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                        />
                        @error('alternate_phone') <span class="text-xs text-red-600 dark:text-red-400 mt-1 block">{{ $message }}</span> @enderror
                    </div>
                </div>

                <div class="pt-2 flex flex-col sm:flex-row sm:items-center justify-between gap-4 border-t border-zinc-100 dark:border-zinc-800">
                    <div class="flex items-center gap-6">
                        <span class="text-xs font-medium text-zinc-700 dark:text-zinc-300">Address Type:</span>
                        <label class="inline-flex items-center gap-1.5 text-xs text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            <input type="radio" value="home" wire:model="type" class="text-amber-600 focus:ring-amber-500" />
                            <span>Home (All day delivery)</span>
                        </label>
                        <label class="inline-flex items-center gap-1.5 text-xs text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            <input type="radio" value="work" wire:model="type" class="text-amber-600 focus:ring-amber-500" />
                            <span>Work (10 AM - 5 PM)</span>
                        </label>
                    </div>

                    <div>
                        <label class="inline-flex items-center gap-2 text-xs text-zinc-700 dark:text-zinc-300 cursor-pointer">
                            <input type="checkbox" wire:model="is_default" class="rounded-sm border-zinc-300 text-amber-600 focus:ring-amber-500" />
                            <span>Make this my default address</span>
                        </label>
                    </div>
                </div>

                <div class="pt-4 flex items-center gap-3">
                    <button
                        type="submit"
                        class="rounded-xl bg-amber-500 px-5 py-2 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-amber-600 transition"
                    >
                        {{ $editingAddressId ? 'Update Address' : 'Save Address' }}
                    </button>
                    <button
                        type="button"
                        wire:click="cancelForm"
                        class="rounded-xl border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-400 dark:hover:bg-zinc-800 transition"
                    >
                        Cancel
                    </button>
                </div>
            </form>
        </div>
    @endif

    <!-- Addresses List -->
    <div class="space-y-4">
        @if ($this->addresses->isEmpty())
            <div class="rounded-2xl border border-zinc-200 bg-white p-12 text-center shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <svg class="mx-auto size-12 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M17.657 16.657L13.414 20.9a1.998 1.998 0 01-2.827 0l-4.244-4.243a8 8 0 1111.314 0z" />
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 11a3 3 0 11-6 0 3 3 0 016 0z" />
                </svg>
                <h3 class="mt-3 text-sm font-bold text-zinc-900 dark:text-white">No addresses saved yet</h3>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Add a delivery address to checkout faster on future purchases.
                </p>
                <div class="mt-5">
                    <button
                        type="button"
                        wire:click="openCreateModal"
                        class="inline-flex items-center gap-1.5 rounded-xl bg-amber-500 px-4 py-2 text-xs font-bold uppercase tracking-wider text-white shadow-xs hover:bg-amber-600 transition"
                    >
                        + Add Your First Address
                    </button>
                </div>
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
                @foreach ($this->addresses as $addr)
                    <div class="relative rounded-2xl border {{ $addr->is_default ? 'border-amber-400 bg-amber-50/20 dark:border-amber-500/40 dark:bg-amber-950/10' : 'border-zinc-200 bg-white dark:border-zinc-800 dark:bg-zinc-900' }} p-6 shadow-xs flex flex-col justify-between">
                        <div>
                            <div class="flex items-center justify-between gap-2 border-b border-zinc-100 pb-3 dark:border-zinc-800">
                                <div class="flex items-center gap-2">
                                    <span class="rounded-md bg-zinc-100 px-2 py-0.5 text-[10px] font-bold uppercase tracking-wider text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                        {{ $addr->type }}
                                    </span>
                                    <h4 class="text-sm font-bold text-zinc-900 dark:text-white">{{ $addr->name }}</h4>
                                </div>

                                @if ($addr->is_default)
                                    <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-[11px] font-bold text-emerald-700 dark:bg-emerald-950/50 dark:text-emerald-400 border border-emerald-200 dark:border-emerald-800">
                                        Default
                                    </span>
                                @endif
                            </div>

                            <div class="mt-3 space-y-1 text-xs text-zinc-600 dark:text-zinc-400">
                                <p class="text-zinc-800 dark:text-zinc-200 font-medium">{{ $addr->address_line }}</p>
                                <p>{{ $addr->locality }}</p>
                                @if ($addr->landmark)
                                    <p class="text-zinc-500 dark:text-zinc-500">Landmark: {{ $addr->landmark }}</p>
                                @endif
                                <p class="font-medium text-zinc-800 dark:text-zinc-200">
                                    {{ $addr->city }}, {{ $addr->state }} - <span class="font-bold">{{ $addr->pincode }}</span>
                                </p>
                                <p class="pt-1 text-zinc-700 dark:text-zinc-300">
                                    Phone: <strong class="text-zinc-900 dark:text-white">{{ $addr->phone }}</strong>
                                    @if ($addr->alternate_phone)
                                        <span class="text-zinc-400"> / {{ $addr->alternate_phone }}</span>
                                    @endif
                                </p>
                            </div>
                        </div>

                        <div class="mt-6 flex flex-wrap items-center justify-between gap-2 border-t border-zinc-100 pt-3 dark:border-zinc-800">
                            <div class="flex items-center gap-3">
                                <button
                                    type="button"
                                    wire:click="openEditModal({{ $addr->id }})"
                                    class="text-xs font-semibold text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white"
                                >
                                    Edit
                                </button>
                                <span class="text-zinc-300 dark:text-zinc-700">&middot;</span>
                                <button
                                    type="button"
                                    wire:click="deleteAddress({{ $addr->id }})"
                                    wire:confirm="Are you sure you want to delete this address?"
                                    class="text-xs font-semibold text-red-600 hover:text-red-700 dark:text-red-400"
                                >
                                    Delete
                                </button>
                            </div>

                            @if (! $addr->is_default)
                                <button
                                    type="button"
                                    wire:click="setDefault({{ $addr->id }})"
                                    class="text-xs font-semibold text-amber-600 hover:text-amber-700 dark:text-amber-400"
                                >
                                    Set as Default
                                </button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

