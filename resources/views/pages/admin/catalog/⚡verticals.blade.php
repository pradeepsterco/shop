<?php

use App\Models\Category;
use App\Models\Vertical;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Manage Verticals')] class extends Component {
    use WithPagination;

    public string $name = '';
    public ?int $category_id = null;
    public string $hsn_code = '';
    public string $gst_rate = '18.00';
    public bool $is_active = true;
    public bool $is_returnable = true;
    public string $return_window_days = '';

    public bool $showModal = false;
    public ?int $editingVerticalId = null;

    public string $search = '';

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'category_id' => [
                'required',
                'integer',
                'exists:categories,id',
                function ($attribute, $value, $fail) {
                    $cat = Category::find($value);
                    if ($cat && ! $cat->canHaveVerticals()) {
                        $fail('Verticals can only be assigned to leaf categories (categories with no sub-categories).');
                    }
                },
            ],
            'hsn_code' => ['required', 'string', 'min:2', 'max:20'],
            'gst_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['boolean'],
            'is_returnable' => ['boolean'],
            // Blank = returns.default_return_window_days
            'return_window_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ];
    }

    public function openCreateModal(): void
    {
        $this->authorize('catalog.verticals');

        $this->reset(['name', 'category_id', 'hsn_code', 'gst_rate', 'is_active', 'is_returnable', 'return_window_days', 'editingVerticalId']);
        $this->gst_rate = '18.00';
        $this->is_active = true;
        $this->showModal = true;
    }

    public function openEditModal(int $id): void
    {
        $this->authorize('catalog.verticals');

        $v = Vertical::findOrFail($id);
        $this->editingVerticalId = $v->id;
        $this->name = $v->name;
        $this->category_id = $v->category_id;
        $this->hsn_code = $v->hsn_code;
        $this->gst_rate = (string) $v->gst_rate;
        $this->is_active = $v->is_active;
        $this->is_returnable = $v->is_returnable;
        $this->return_window_days = $v->return_window_days !== null ? (string) $v->return_window_days : '';
        $this->showModal = true;
    }

    public function saveVertical(): void
    {
        $this->authorize('catalog.verticals');

        $this->validate();

        if ($this->editingVerticalId) {
            $v = Vertical::findOrFail($this->editingVerticalId);
            $v->update([
                'name' => $this->name,
                'category_id' => $this->category_id,
                'hsn_code' => $this->hsn_code,
                'gst_rate' => (float) $this->gst_rate,
                'is_active' => $this->is_active,
                'is_returnable' => $this->is_returnable,
                'return_window_days' => $this->return_window_days === '' ? null : (int) $this->return_window_days,
                'slug' => Vertical::generateUniqueSlug($this->name, $v->id),
            ]);

            session()->flash('status', "Vertical [{$v->name}] updated.");
        } else {
            $v = Vertical::create([
                'name' => $this->name,
                'category_id' => $this->category_id,
                'hsn_code' => $this->hsn_code,
                'gst_rate' => (float) $this->gst_rate,
                'is_active' => $this->is_active,
                'is_returnable' => $this->is_returnable,
                'return_window_days' => $this->return_window_days === '' ? null : (int) $this->return_window_days,
            ]);

            session()->flash('status', "Vertical [{$v->name}] created.");
        }

        $this->showModal = false;
        $this->reset(['name', 'category_id', 'hsn_code', 'gst_rate', 'is_returnable', 'return_window_days', 'editingVerticalId']);
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('catalog.verticals');

        $v = Vertical::findOrFail($id);
        $v->update(['is_active' => ! $v->is_active]);

        session()->flash('status', "Vertical [{$v->name}] status toggled.");
    }

    /**
     * Eligible leaf categories for vertical assignment.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Category>
     */
    public function getLeafCategoriesProperty()
    {
        return Category::whereDoesntHave('children')
            ->where('is_active', true)
            ->orderBy('name')
            ->get();
    }

    /**
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function getVerticalsProperty()
    {
        return Vertical::with(['category', 'attributes'])
            ->withCount(['attributes', 'products'])
            ->when($this->search !== '', function ($q) {
                $q->where('name', 'like', "%{$this->search}%")
                    ->orWhere('slug', 'like', "%{$this->search}%")
                    ->orWhere('hsn_code', 'like', "%{$this->search}%");
            })
            ->latest('id')
            ->paginate(15);
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Product Verticals</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                A vertical represents a specific product type (e.g. T-Shirt, Smartphone) tied to a leaf category, defining tax rates and attributes.
            </p>
        </div>

        <div>
            <button wire:click="openCreateModal" type="button" data-test="create-vertical-btn"
                    class="inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                <span>Add Vertical</span>
            </button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-900/50 dark:bg-green-950/30 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    <!-- Search filter -->
    <div class="flex items-center gap-4">
        <div class="relative max-w-sm flex-1">
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search verticals or HSN..."
                   class="block w-full rounded-lg border border-zinc-300 bg-white py-2 pl-9 pr-3 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
            <svg class="pointer-events-none absolute left-3 top-2.5 size-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>
    </div>

    <!-- Table -->
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400">
                    <tr>
                        <th class="px-6 py-3.5">Vertical Name</th>
                        <th class="px-6 py-3.5">Category Breadcrumb</th>
                        <th class="px-6 py-3.5">Tax & HSN</th>
                        <th class="px-6 py-3.5 text-center">Attributes</th>
                        <th class="px-6 py-3.5 text-center">Products</th>
                        <th class="px-6 py-3.5 text-center">Status</th>
                        <th class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($this->verticals as $v)
                        <tr wire:key="v-{{ $v->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition">
                            <td class="px-6 py-4 font-medium text-zinc-900 dark:text-white">
                                <div>{{ $v->name }}</div>
                                <div class="font-mono text-xs text-zinc-400">/{{ $v->slug }}</div>
                            </td>
                            <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                                {{ $v->category ? $v->category->breadcrumbString() : '—' }}
                            </td>
                            <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                                <div>HSN: <span class="font-mono font-semibold">{{ $v->hsn_code }}</span></div>
                                <div class="text-zinc-400">GST: {{ number_format($v->gst_rate, 1) }}%</div>
                                <div class="text-zinc-400" data-test="vertical-return-policy">
                                    {{ $v->is_returnable ? 'Returns: '.($v->return_window_days ?? config('returns.default_return_window_days')).' days' : 'Not returnable (claims only)' }}
                                </div>
                            </td>
                            <td class="px-6 py-4 text-center">
                                <a href="{{ route('admin.catalog.vertical-attributes', $v) }}"
                                   wire:navigate
                                   class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 bg-white px-2.5 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700 transition">
                                    <span>{{ $v->attributes_count }} specs</span>
                                    <svg class="size-3.5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                                    </svg>
                                </a>
                            </td>
                            <td class="px-6 py-4 text-center text-xs text-zinc-500">
                                {{ $v->products_count }}
                            </td>
                            <td class="px-6 py-4 text-center">
                                <button wire:click="toggleActive({{ $v->id }})" type="button"
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold cursor-pointer transition {{ $v->is_active ? 'bg-green-50 text-green-700 dark:bg-green-950/50 dark:text-green-400 hover:bg-green-100' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400 hover:bg-zinc-200' }}">
                                    {{ $v->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('admin.catalog.vertical-attributes', $v) }}"
                                       wire:navigate
                                       title="Manage attributes"
                                       class="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:hover:bg-zinc-800 dark:hover:text-white">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                                        </svg>
                                    </a>
                                    <button wire:click="openEditModal({{ $v->id }})" type="button" title="Edit vertical"
                                            class="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:hover:bg-zinc-800 dark:hover:text-white">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center text-sm text-zinc-400">
                                No verticals found. Click "Add Vertical" to set up your first product type.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->verticals->hasPages())
            <div class="border-t border-zinc-200 px-6 py-4 dark:border-zinc-800">
                {{ $this->verticals->links() }}
            </div>
        @endif
    </div>

    <!-- Create / Edit Modal -->
    @if ($showModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="$set('showModal', false)" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-lg rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between pb-4 border-b border-zinc-100 dark:border-zinc-800">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">
                        {{ $editingVerticalId ? 'Edit Vertical' : 'Add New Vertical' }}
                    </h3>
                    <button wire:click="$set('showModal', false)" type="button" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form wire:submit="saveVertical" class="mt-4 space-y-4">
                    <div>
                        <label for="v-name" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Vertical Name *</label>
                        <input wire:model="name" type="text" id="v-name" required placeholder="e.g. T-Shirt, Smartphone, Running Shoes"
                               class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('name') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="v-category" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Leaf Category *</label>
                        <select wire:model="category_id" id="v-category" required
                                class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                            <option value="">— Select Leaf Category —</option>
                            @foreach ($this->leafCategories as $lc)
                                <option value="{{ $lc->id }}">{{ $lc->breadcrumbString() }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-zinc-400">Verticals can only be placed under a leaf category without subcategories.</p>
                        @error('category_id') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="v-hsn" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">HSN Code *</label>
                            <input wire:model="hsn_code" type="text" id="v-hsn" required placeholder="e.g. 6109"
                                   class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            @error('hsn_code') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="v-gst" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">GST Rate (%) *</label>
                            <input wire:model="gst_rate" type="number" step="0.01" min="0" max="100" id="v-gst" required placeholder="18.00"
                                   class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            @error('gst_rate') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label class="flex items-center gap-2 cursor-pointer pt-7">
                                <input wire:model="is_returnable" type="checkbox" data-test="vertical-returnable" class="size-4 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800" />
                                <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Returnable</span>
                            </label>
                            <p class="mt-1 text-xs text-zinc-500">Non-returnable still allows damaged, defective, wrong or missing item claims ({{ config('returns.claim_window_days') }} days).</p>
                        </div>
                        <div>
                            <label for="v-return-window" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Return window (days)</label>
                            <input wire:model="return_window_days" type="number" min="1" max="90" id="v-return-window" placeholder="Default {{ config('returns.default_return_window_days') }}"
                                   class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            @error('return_window_days') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label class="flex items-center gap-2 cursor-pointer pt-2">
                            <input wire:model="is_active" type="checkbox" class="size-4 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800" />
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">Active (Visible to sellers when adding products)</span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="button" wire:click="$set('showModal', false)" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                            Cancel
                        </button>
                        <button type="submit" data-test="submit-vertical-btn" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                            {{ $editingVerticalId ? 'Update Vertical' : 'Create Vertical' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

