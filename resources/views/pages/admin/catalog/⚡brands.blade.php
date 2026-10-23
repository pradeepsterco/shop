<?php

use App\Enums\BrandStatus;
use App\Models\Brand;
use App\Services\AdminActivityLogger;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Brand Management')] class extends Component {
    use WithFileUploads, WithPagination;

    public string $name = '';
    public mixed $logo = null;

    public string $status_filter = 'all';
    public string $search = '';

    public bool $showCreateModal = false;
    public bool $showRejectModal = false;
    public ?int $selectedBrandId = null;
    public string $rejection_reason = '';

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100', 'unique:brands,name'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ];
    }

    public function openCreateModal(): void
    {
        $this->authorize('catalog.brands.manage');

        $this->reset(['name', 'logo']);
        $this->showCreateModal = true;
    }

    public function createBrand(): void
    {
        $this->authorize('catalog.brands.manage');

        $this->validate();

        $logoPath = null;
        if ($this->logo) {
            $logoPath = $this->logo->store('brand-logos', 'public');
        }

        $brand = Brand::create([
            'name' => $this->name,
            'logo_path' => $logoPath,
            'status' => BrandStatus::Approved, // Admin created brands are approved by default
        ]);

        $this->showCreateModal = false;
        $this->reset(['name', 'logo']);

        session()->flash('status', "Brand [{$brand->name}] created and approved.");
    }

    public function approveBrand(int $id): void
    {
        $this->authorize('catalog.brands.review');

        $brand = Brand::findOrFail($id);
        $brand->approve();

        AdminActivityLogger::log('brand.approved', $brand, [
            'brand_id' => $brand->id,
            'name' => $brand->name,
        ]);

        session()->flash('status', "Brand [{$brand->name}] approved.");
    }

    public function openRejectModal(int $id): void
    {
        $this->authorize('catalog.brands.review');

        $this->selectedBrandId = $id;
        $this->rejection_reason = '';
        $this->showRejectModal = true;
    }

    public function confirmReject(): void
    {
        $this->authorize('catalog.brands.review');

        $this->validate([
            'rejection_reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);

        if (! $this->selectedBrandId) {
            return;
        }

        $brand = Brand::findOrFail($this->selectedBrandId);
        $brand->reject($this->rejection_reason);

        AdminActivityLogger::log('brand.rejected', $brand, [
            'brand_id' => $brand->id,
            'name' => $brand->name,
            'reason' => $this->rejection_reason,
        ]);

        $this->showRejectModal = false;
        $this->reset(['selectedBrandId', 'rejection_reason']);

        session()->flash('status', "Brand [{$brand->name}] rejected.");
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getBrandsProperty()
    {
        return Brand::with('requester.user')
            ->withCount('products')
            ->when($this->status_filter !== 'all', fn ($q) => $q->where('status', $this->status_filter))
            ->when($this->search !== '', function ($q) {
                $q->where(function ($sub) {
                    $sub->where('name', 'like', "%{$this->search}%")
                        ->orWhere('slug', 'like', "%{$this->search}%");
                });
            })
            ->latest('id')
            ->paginate(15);
    }

    public function getPendingCountProperty(): int
    {
        return Brand::where('status', BrandStatus::Pending)->count();
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Brand Directory</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Manage global approved catalog brands and review seller brand authorization requests.
            </p>
        </div>

        <div>
            <button wire:click="openCreateModal" type="button" data-test="create-brand-btn"
                    class="inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                <span>Add Brand</span>
            </button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-900/50 dark:bg-green-950/30 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    <!-- Tabs & Filter Bar -->
    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 border-b border-zinc-200 dark:border-zinc-800 pb-4">
        <div class="flex items-center gap-2 overflow-x-auto">
            <button wire:click="$set('status_filter', 'all')" type="button"
                    class="rounded-lg px-3 py-1.5 text-xs font-semibold transition {{ $status_filter === 'all' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}">
                All Brands
            </button>
            <button wire:click="$set('status_filter', 'pending')" type="button"
                    class="relative rounded-lg px-3 py-1.5 text-xs font-semibold transition {{ $status_filter === 'pending' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}">
                <span>Pending Review</span>
                @if ($this->pendingCount > 0)
                    <span class="ml-1.5 inline-flex items-center rounded-full bg-amber-500 px-1.5 py-0.2 text-[10px] font-bold text-white">
                        {{ $this->pendingCount }}
                    </span>
                @endif
            </button>
            <button wire:click="$set('status_filter', 'approved')" type="button"
                    class="rounded-lg px-3 py-1.5 text-xs font-semibold transition {{ $status_filter === 'approved' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}">
                Approved
            </button>
            <button wire:click="$set('status_filter', 'rejected')" type="button"
                    class="rounded-lg px-3 py-1.5 text-xs font-semibold transition {{ $status_filter === 'rejected' ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}">
                Rejected
            </button>
        </div>

        <div class="relative max-w-xs flex-1">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search brand name..."
                   class="block w-full rounded-lg border border-zinc-300 bg-white py-1.5 pl-8 pr-3 text-xs text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
            <svg class="pointer-events-none absolute left-2.5 top-2 size-3.5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>
    </div>

    <!-- Brands Table -->
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400">
                    <tr>
                        <th class="px-6 py-3.5">Brand</th>
                        <th class="px-6 py-3.5">Slug</th>
                        <th class="px-6 py-3.5">Requested By</th>
                        <th class="px-6 py-3.5 text-center">Products</th>
                        <th class="px-6 py-3.5 text-center">Status</th>
                        <th class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($this->brands as $brand)
                        <tr wire:key="brand-{{ $brand->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    @if ($brand->logoUrl())
                                        <img src="{{ $brand->logoUrl() }}" alt="{{ $brand->name }}" class="size-8 rounded-md object-contain border border-zinc-200 dark:border-zinc-700" />
                                    @else
                                        <span class="flex size-8 items-center justify-center rounded-md bg-zinc-100 font-bold text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                            {{ strtoupper(substr($brand->name, 0, 2)) }}
                                        </span>
                                    @endif
                                    <span class="font-medium text-zinc-900 dark:text-white">{{ $brand->name }}</span>
                                </div>
                            </td>
                            <td class="px-6 py-4 font-mono text-xs text-zinc-500">
                                {{ $brand->slug }}
                            </td>
                            <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                                @if ($brand->requester)
                                    <div><strong>{{ $brand->requester->store_name }}</strong></div>
                                    <div class="text-[11px] text-zinc-400">{{ $brand->requester->user->email }}</div>
                                @else
                                    <span class="text-zinc-400">Platform Admin</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-center text-xs text-zinc-500">
                                {{ $brand->products_count }}
                            </td>
                            <td class="px-6 py-4 text-center">
                                @if ($brand->status === BrandStatus::Approved)
                                    <span class="inline-flex items-center rounded-full bg-green-50 px-2.5 py-0.5 text-xs font-semibold text-green-700 dark:bg-green-950/50 dark:text-green-400 border border-green-200 dark:border-green-800">
                                        Approved
                                    </span>
                                @elseif ($brand->status === BrandStatus::Pending)
                                    <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 dark:bg-amber-950/50 dark:text-amber-400 border border-amber-200 dark:border-amber-800">
                                        Pending Review
                                    </span>
                                @elseif ($brand->status === BrandStatus::Rejected)
                                    <div>
                                        <span class="inline-flex items-center rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 dark:bg-red-950/50 dark:text-red-400 border border-red-200 dark:border-red-800">
                                            Rejected
                                        </span>
                                        @if ($brand->rejection_reason)
                                            <p class="mt-1 text-[11px] text-red-500 max-w-xs truncate" title="{{ $brand->rejection_reason }}">
                                                {{ $brand->rejection_reason }}
                                            </p>
                                        @endif
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if ($brand->status === BrandStatus::Pending)
                                        <button wire:click="approveBrand({{ $brand->id }})" type="button" data-test="approve-brand-btn"
                                                class="rounded-lg bg-emerald-600 px-2.5 py-1 text-xs font-semibold text-white hover:bg-emerald-700 transition shadow-xs">
                                            Approve
                                        </button>
                                        <button wire:click="openRejectModal({{ $brand->id }})" type="button" data-test="reject-brand-btn"
                                                class="rounded-lg border border-red-300 bg-white px-2.5 py-1 text-xs font-semibold text-red-600 hover:bg-red-50 dark:border-red-800 dark:bg-zinc-800 dark:text-red-400 transition">
                                            Reject
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-6 py-12 text-center text-sm text-zinc-400">
                                No brands found matching your filter criteria.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->brands->hasPages())
            <div class="border-t border-zinc-200 px-6 py-4 dark:border-zinc-800">
                {{ $this->brands->links() }}
            </div>
        @endif
    </div>

    <!-- Create Brand Modal -->
    @if ($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="$set('showCreateModal', false)" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-md rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between pb-4 border-b border-zinc-100 dark:border-zinc-800">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Add Global Brand</h3>
                    <button wire:click="$set('showCreateModal', false)" type="button" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form wire:submit="createBrand" class="mt-4 space-y-4">
                    <div>
                        <label for="brand-name" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Brand Name *</label>
                        <input wire:model="name" type="text" id="brand-name" required placeholder="e.g. Nike, Apple, Puma"
                               class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('name') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Brand Logo (Optional)</label>
                        @if ($logo)
                            <div class="mt-2 flex items-center gap-3">
                                <img src="{{ $logo->temporaryUrl() }}" alt="Preview" class="size-12 rounded-md object-contain border border-zinc-200 dark:border-zinc-700" />
                                <span class="text-xs text-zinc-500">Logo preview</span>
                            </div>
                        @endif
                        <input wire:model="logo" type="file" accept="image/*" class="mt-2 block w-full text-xs text-zinc-500 file:mr-4 file:py-1.5 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-800 dark:file:text-zinc-300" />
                        @error('logo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="button" wire:click="$set('showCreateModal', false)" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                            Cancel
                        </button>
                        <button type="submit" data-test="submit-brand-btn" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                            Create & Approve
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Reject Brand Modal -->
    @if ($showRejectModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="$set('showRejectModal', false)" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-md rounded-xl border border-red-200 bg-white p-6 shadow-xl dark:border-red-900/50 dark:bg-zinc-900">
                <h3 class="text-lg font-bold text-red-600 dark:text-red-400">Reject Brand Request</h3>
                <p class="mt-1 text-xs text-zinc-500">
                    Provide a clear explanation to the seller (e.g. proof of trademark authorization missing, trademark infringement).
                </p>

                <form wire:submit="confirmReject" class="mt-4 space-y-4">
                    <div>
                        <textarea wire:model="rejection_reason" rows="3" required placeholder="e.g. Trademark authorization letter is missing or invalid."
                                  class="block w-full rounded-lg border border-red-300 bg-white p-2.5 text-sm text-zinc-900 focus:border-red-500 focus:outline-hidden focus:ring-1 focus:ring-red-500 dark:border-red-800 dark:bg-zinc-800 dark:text-white"></textarea>
                        @error('rejection_reason') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end gap-3 pt-2">
                        <button type="button" wire:click="$set('showRejectModal', false)" class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                            Cancel
                        </button>
                        <button type="submit" data-test="confirm-reject-brand-btn" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700 transition shadow-xs">
                            Confirm Rejection
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

