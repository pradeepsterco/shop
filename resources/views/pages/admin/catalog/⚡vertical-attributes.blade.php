<?php

use App\Enums\AttributeType;
use App\Models\Vertical;
use App\Models\VerticalAttribute;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Vertical Attributes Builder')] class extends Component {
    public Vertical $vertical;

    public string $name = '';
    public string $code = '';
    public string $type = 'text';
    public ?string $unit = null;
    public bool $is_required = false;
    public bool $is_variant = false;
    public int $sort_order = 0;

    // For options editing
    public string $new_option_label = '';
    public string $new_option_value = '';

    public bool $showAttributeModal = false;
    public ?int $editingAttributeId = null;
    public bool $isEditingLocked = false;

    public bool $showOptionsModal = false;
    public ?VerticalAttribute $managingOptionsAttribute = null;

    public function mount(Vertical $vertical): void
    {
        $this->vertical = $vertical->load('category');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'code' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[a-z0-9_]+$/'],
            'type' => ['required', 'string', 'in:text,number,select,multiselect,boolean'],
            'unit' => ['nullable', 'string', 'max:20'],
            'is_required' => ['boolean'],
            'is_variant' => ['boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function updatedName(string $value): void
    {
        if (! $this->editingAttributeId) {
            $this->code = Str::slug($value, '_');
        }
    }

    public function openCreateModal(): void
    {
        $this->authorize('catalog.attributes');

        $this->reset(['name', 'code', 'type', 'unit', 'is_required', 'is_variant', 'editingAttributeId', 'isEditingLocked']);
        $this->type = 'text';
        $this->sort_order = ($this->vertical->attributes()->max('sort_order') ?? 0) + 1;
        $this->showAttributeModal = true;
    }

    public function openEditModal(int $id): void
    {
        $this->authorize('catalog.attributes');

        $attr = VerticalAttribute::where('vertical_id', $this->vertical->id)->findOrFail($id);
        $this->editingAttributeId = $attr->id;
        $this->name = $attr->name;
        $this->code = $attr->code;
        $this->type = $attr->type->value;
        $this->unit = $attr->unit;
        $this->is_required = $attr->is_required;
        $this->is_variant = $attr->is_variant;
        $this->sort_order = $attr->sort_order;
        $this->isEditingLocked = $attr->isUsedInProducts();
        $this->showAttributeModal = true;
    }

    public function saveAttribute(): void
    {
        $this->authorize('catalog.attributes');

        $this->validate();

        if ($this->editingAttributeId) {
            $attr = VerticalAttribute::where('vertical_id', $this->vertical->id)->findOrFail($this->editingAttributeId);

            if ($attr->isUsedInProducts()) {
                // If locked, code, type, and is_variant must remain unchanged
                if ($this->code !== $attr->code || $this->type !== $attr->type->value || $this->is_variant !== $attr->is_variant) {
                    $this->addError('code', 'Code, type, and variant flag are locked because this attribute is used in catalog products.');

                    return;
                }
            }

            // Check code uniqueness within vertical
            $exists = VerticalAttribute::where('vertical_id', $this->vertical->id)
                ->where('code', $this->code)
                ->where('id', '!=', $attr->id)
                ->exists();

            if ($exists) {
                $this->addError('code', "An attribute with code '{$this->code}' already exists in this vertical.");

                return;
            }

            $attr->update([
                'name' => $this->name,
                'code' => $this->code,
                'type' => AttributeType::from($this->type),
                'unit' => $this->unit,
                'is_required' => $this->is_required,
                'is_variant' => $this->is_variant,
                'sort_order' => $this->sort_order,
            ]);

            session()->flash('status', "Attribute [{$attr->name}] updated.");
        } else {
            $exists = VerticalAttribute::where('vertical_id', $this->vertical->id)
                ->where('code', $this->code)
                ->exists();

            if ($exists) {
                $this->addError('code', "An attribute with code '{$this->code}' already exists in this vertical.");

                return;
            }

            VerticalAttribute::create([
                'vertical_id' => $this->vertical->id,
                'name' => $this->name,
                'code' => $this->code,
                'type' => AttributeType::from($this->type),
                'unit' => $this->unit,
                'is_required' => $this->is_required,
                'is_variant' => $this->is_variant,
                'sort_order' => $this->sort_order,
                'options' => in_array($this->type, ['select', 'multiselect'], true) ? [] : null,
            ]);

            session()->flash('status', "Attribute [{$this->name}] created.");
        }

        $this->showAttributeModal = false;
        $this->reset(['name', 'code', 'type', 'unit', 'is_required', 'is_variant', 'editingAttributeId']);
    }

    public function openOptionsModal(int $id): void
    {
        $this->authorize('catalog.attributes');

        $this->managingOptionsAttribute = VerticalAttribute::where('vertical_id', $this->vertical->id)->findOrFail($id);
        $this->reset(['new_option_label', 'new_option_value']);
        $this->showOptionsModal = true;
    }

    public function updatedNewOptionLabel(string $value): void
    {
        if (blank($this->new_option_value)) {
            $this->new_option_value = Str::slug($value);
        }
    }

    public function addOption(): void
    {
        $this->authorize('catalog.attributes');

        $this->validate([
            'new_option_label' => ['required', 'string', 'min:1', 'max:100'],
            'new_option_value' => ['required', 'string', 'min:1', 'max:100'],
        ]);

        if (! $this->managingOptionsAttribute) {
            return;
        }

        $options = $this->managingOptionsAttribute->options ?? [];

        // Check value uniqueness
        foreach ($options as $opt) {
            if ($opt['value'] === $this->new_option_value) {
                $this->addError('new_option_value', "Option value '{$this->new_option_value}' already exists.");

                return;
            }
        }

        $options[] = [
            'label' => trim($this->new_option_label),
            'value' => trim($this->new_option_value),
            'is_active' => true,
        ];

        $this->managingOptionsAttribute->update(['options' => $options]);
        $this->managingOptionsAttribute->refresh();
        $this->reset(['new_option_label', 'new_option_value']);

        session()->flash('options_status', 'Option added successfully.');
    }

    public function toggleOptionActive(int $index): void
    {
        $this->authorize('catalog.attributes');

        if (! $this->managingOptionsAttribute) {
            return;
        }

        $options = $this->managingOptionsAttribute->options ?? [];
        if (! isset($options[$index])) {
            return;
        }

        $options[$index]['is_active'] = ! ($options[$index]['is_active'] ?? true);
        $this->managingOptionsAttribute->update(['options' => $options]);
        $this->managingOptionsAttribute->refresh();
    }

    public function toggleOptionRetired(int $index): void
    {
        $this->authorize('catalog.attributes');

        if (! $this->managingOptionsAttribute) {
            return;
        }

        $options = $this->managingOptionsAttribute->options ?? [];
        if (! isset($options[$index])) {
            return;
        }

        $isRetired = ! empty($options[$index]['is_retired']);
        $options[$index]['is_retired'] = ! $isRetired;
        if (! $isRetired) {
            $options[$index]['is_active'] = false;
        }

        $this->managingOptionsAttribute->update(['options' => $options]);
        $this->managingOptionsAttribute->refresh();
    }

    public function removeOption(int $index): void
    {
        $this->authorize('catalog.attributes');

        if (! $this->managingOptionsAttribute) {
            return;
        }

        $options = $this->managingOptionsAttribute->options ?? [];
        if (! isset($options[$index])) {
            return;
        }

        $optionValue = $options[$index]['value'];

        // Enforce immutability rule: option slugs used by variants are immutable and cannot be deleted
        if ($this->managingOptionsAttribute->isOptionUsed($optionValue)) {
            session()->flash('options_error', "Option slug '{$optionValue}' is immutable because it is used by product variants. It cannot be deleted. Deactivate or retire it instead.");

            return;
        }

        unset($options[$index]);
        $this->managingOptionsAttribute->update(['options' => array_values($options)]);
        $this->managingOptionsAttribute->refresh();
    }

    public function deleteAttribute(int $id): void
    {
        $this->authorize('catalog.attributes');

        $attr = VerticalAttribute::where('vertical_id', $this->vertical->id)->findOrFail($id);

        if ($attr->isUsedInProducts()) {
            session()->flash('error', "Attribute [{$attr->name}] cannot be deleted because it is used in catalog products.");

            return;
        }

        $attr->delete();
        session()->flash('status', "Attribute [{$attr->name}] deleted.");
    }
}; ?>

<div class="space-y-6">
    <!-- Breadcrumb Header -->
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2 text-xs text-zinc-500">
                <a href="{{ route('admin.catalog.verticals') }}" wire:navigate class="hover:underline">Verticals</a>
                <span>›</span>
                <span>{{ $vertical->category?->breadcrumbString() }}</span>
                <span>›</span>
                <span class="font-semibold text-zinc-900 dark:text-white">{{ $vertical->name }}</span>
            </div>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                Attributes for {{ $vertical->name }}
            </h2>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                Define the schema of specifications and variant dimensions. Attributes used by products are locked from type/code edits.
            </p>
        </div>

        <div class="flex items-center gap-3">
            <a href="{{ route('admin.catalog.verticals') }}" wire:navigate
               class="rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700 transition">
                ← Back to Verticals
            </a>
            <button wire:click="openCreateModal" type="button" data-test="add-attribute-btn"
                    class="inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                <span>Add Attribute</span>
            </button>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-900/50 dark:bg-green-950/30 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    @if (session('error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
            {{ session('error') }}
        </div>
    @endif

    <!-- Attributes Table -->
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400">
                    <tr>
                        <th class="px-6 py-3.5">Name & Code</th>
                        <th class="px-6 py-3.5">Type & Unit</th>
                        <th class="px-6 py-3.5 text-center">Dimension</th>
                        <th class="px-6 py-3.5 text-center">Required</th>
                        <th class="px-6 py-3.5 text-center">Options</th>
                        <th class="px-6 py-3.5 text-center">Sort</th>
                        <th class="px-6 py-3.5 text-center">Status</th>
                        <th class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($vertical->attributes as $attr)
                        @php
                            $isUsed = $attr->isUsedInProducts();
                        @endphp
                        <tr wire:key="attr-{{ $attr->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition">
                            <td class="px-6 py-4 font-medium text-zinc-900 dark:text-white">
                                <div class="flex items-center gap-2">
                                    <span>{{ $attr->name }}</span>
                                    @if ($isUsed)
                                        <span class="inline-flex items-center gap-1 rounded-sm bg-amber-50 px-1.5 py-0.5 text-[10px] font-semibold text-amber-700 dark:bg-amber-950/50 dark:text-amber-300 border border-amber-200 dark:border-amber-800" title="Locked: In use by catalog products">
                                            <svg class="size-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
                                            </svg>
                                            Locked
                                        </span>
                                    @endif
                                </div>
                                <div class="font-mono text-xs text-zinc-400">{{ $attr->code }}</div>
                            </td>
                            <td class="px-6 py-4 text-xs text-zinc-600 dark:text-zinc-300">
                                <div class="font-medium">{{ $attr->type->label() }}</div>
                                @if ($attr->unit)
                                    <div class="text-zinc-400">Unit: {{ $attr->unit }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-center">
                                @if ($attr->is_variant)
                                    <span class="inline-flex items-center rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700 dark:bg-blue-950/50 dark:text-blue-300 border border-blue-200 dark:border-blue-800">
                                        Variant
                                    </span>
                                @else
                                    <span class="text-xs text-zinc-400">Specification</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-center">
                                @if ($attr->is_required)
                                    <span class="text-xs font-semibold text-red-600 dark:text-red-400">Required</span>
                                @else
                                    <span class="text-xs text-zinc-400">Optional</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-center">
                                @if ($attr->type->supportsOptions())
                                    <button wire:click="openOptionsModal({{ $attr->id }})" type="button"
                                            class="inline-flex items-center gap-1 rounded-md border border-zinc-200 bg-zinc-50 px-2 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        <span>{{ count($attr->options ?? []) }} options</span>
                                        <svg class="size-3 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6V4m0 2a2 2 0 100 4m0-4a2 2 0 110 4m-6 8a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4m6 6v10m6-2a2 2 0 100-4m0 4a2 2 0 110-4m0 4v2m0-6V4"/>
                                        </svg>
                                    </button>
                                @else
                                    <span class="text-xs text-zinc-400">—</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-center text-xs text-zinc-500">
                                {{ $attr->sort_order }}
                            </td>
                            <td class="px-6 py-4 text-center text-xs">
                                @if ($isUsed)
                                    <span class="text-emerald-600 font-medium">In Use</span>
                                @else
                                    <span class="text-zinc-400">Unused</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <button wire:click="openEditModal({{ $attr->id }})" type="button" title="Edit attribute"
                                            class="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:hover:bg-zinc-800 dark:hover:text-white">
                                        <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                                        </svg>
                                    </button>
                                    @if (! $isUsed)
                                        <button wire:click="deleteAttribute({{ $attr->id }})" wire:confirm="Are you sure you want to delete this attribute?" type="button" title="Delete attribute"
                                                class="rounded-md p-1.5 text-red-500 hover:bg-red-50 hover:text-red-700 dark:hover:bg-red-950/30">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                            </svg>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="8" class="px-6 py-12 text-center text-sm text-zinc-400">
                                No attributes configured for this vertical yet. Click "Add Attribute" to build the schema.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create / Edit Attribute Modal -->
    @if ($showAttributeModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="$set('showAttributeModal', false)" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-lg rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between pb-4 border-b border-zinc-100 dark:border-zinc-800">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">
                        {{ $editingAttributeId ? 'Edit Attribute' : 'Add Vertical Attribute' }}
                    </h3>
                    <button wire:click="$set('showAttributeModal', false)" type="button" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                @if ($isEditingLocked)
                    <div class="mt-4 rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-300">
                        <strong>Integrity Protection:</strong> This attribute is already referenced in catalog products. Its code, type, and variant dimension are locked.
                    </div>
                @endif

                <form wire:submit="saveAttribute" class="mt-4 space-y-4">
                    <div>
                        <label for="attr-name" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Attribute Display Name *</label>
                        <input wire:model.live.debounce.300ms="name" type="text" id="attr-name" required placeholder="e.g. Size, Storage, RAM, Fabric"
                               class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('name') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="attr-code" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Identifier Code *</label>
                            <input wire:model="code" type="text" id="attr-code" required {{ $isEditingLocked ? 'disabled' : '' }}
                                   placeholder="e.g. size, ram, color"
                                   class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-mono text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white {{ $isEditingLocked ? 'bg-zinc-100 cursor-not-allowed dark:bg-zinc-800/50 text-zinc-400' : '' }}" />
                            @error('code') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="attr-type" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Input Type *</label>
                            <select wire:model="type" id="attr-type" required {{ $isEditingLocked ? 'disabled' : '' }}
                                    class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white {{ $isEditingLocked ? 'bg-zinc-100 cursor-not-allowed dark:bg-zinc-800/50 text-zinc-400' : '' }}">
                                <option value="text">Text</option>
                                <option value="number">Number</option>
                                <option value="select">Single Select</option>
                                <option value="multiselect">Multi Select</option>
                                <option value="boolean">Yes / No (Boolean)</option>
                            </select>
                            @error('type') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div>
                            <label for="attr-unit" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Unit (Optional)</label>
                            <input wire:model="unit" type="text" id="attr-unit" placeholder="e.g. GB, cm, mAh, kg"
                                   class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            @error('unit') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="attr-sort" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Sort Order</label>
                            <input wire:model="sort_order" type="number" id="attr-sort" min="0" max="9999" required
                                   class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            @error('sort_order') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div class="space-y-3 pt-2">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input wire:model="is_variant" type="checkbox" {{ $isEditingLocked ? 'disabled' : '' }}
                                   class="size-4 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 {{ $isEditingLocked ? 'cursor-not-allowed opacity-60' : '' }}" />
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                Variant Dimension (Creates buyable variants, e.g. Size, Color, Storage)
                            </span>
                        </label>

                        <label class="flex items-center gap-2 cursor-pointer">
                            <input wire:model="is_required" type="checkbox"
                                   class="size-4 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800" />
                            <span class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                Required Attribute (Seller must specify a value)
                            </span>
                        </label>
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="button" wire:click="$set('showAttributeModal', false)" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                            Cancel
                        </button>
                        <button type="submit" data-test="submit-attribute-btn" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                            {{ $editingAttributeId ? 'Update Attribute' : 'Create Attribute' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Manage Options Modal (for Select / Multiselect) -->
    @if ($showOptionsModal && $managingOptionsAttribute)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="$set('showOptionsModal', false)" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-lg rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between pb-4 border-b border-zinc-100 dark:border-zinc-800">
                    <div>
                        <h3 class="text-lg font-bold text-zinc-900 dark:text-white">
                            Options for {{ $managingOptionsAttribute->name }}
                        </h3>
                        <p class="text-xs text-zinc-500">Add, reorder, or toggle active/hidden options.</p>
                    </div>
                    <button wire:click="$set('showOptionsModal', false)" type="button" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                @if (session('options_status'))
                    <div class="mt-4 rounded-lg border border-green-200 bg-green-50 p-3 text-xs text-green-800 dark:border-green-900/50 dark:bg-green-950/30 dark:text-green-300">
                        {{ session('options_status') }}
                    </div>
                @endif

                @if (session('options_error'))
                    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
                        {{ session('options_error') }}
                    </div>
                @endif

                <!-- Existing Options List -->
                <div class="mt-4 max-h-60 overflow-y-auto divide-y divide-zinc-100 dark:divide-zinc-800 border border-zinc-200 rounded-lg dark:border-zinc-800">
                    @forelse ($managingOptionsAttribute->options ?? [] as $index => $opt)
                        @php
                            $isActive = $opt['is_active'] ?? true;
                            $isRetired = ! empty($opt['is_retired']);
                            $isOptUsed = $managingOptionsAttribute->isOptionUsed($opt['value']);
                        @endphp
                        <div wire:key="opt-{{ $index }}" class="flex items-center justify-between px-3 py-2 text-xs {{ $isRetired ? 'bg-red-50/50 dark:bg-red-950/20' : '' }}">
                            <div class="flex items-center gap-2">
                                <span class="font-medium text-zinc-900 dark:text-white {{ $isRetired ? 'line-through text-zinc-400' : '' }}">{{ $opt['label'] }}</span>
                                <span class="font-mono text-zinc-400 text-[11px]">({{ $opt['value'] }})</span>
                                @if ($isOptUsed)
                                    <span class="text-[10px] text-amber-600 font-semibold">(used)</span>
                                @endif
                                @if ($isRetired)
                                    <span class="text-[10px] text-red-600 font-semibold uppercase">[Retired]</span>
                                @endif
                            </div>

                            <div class="flex items-center gap-2">
                                <button wire:click="toggleOptionActive({{ $index }})" type="button"
                                        class="rounded px-2 py-0.5 text-[11px] font-semibold transition {{ $isActive ? 'bg-green-100 text-green-700 dark:bg-green-950 dark:text-green-300' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400' }}"
                                        title="{{ $isActive ? 'Visible for new products' : 'Hidden from new products' }}">
                                    {{ $isActive ? 'Active' : 'Hidden' }}
                                </button>

                                <button wire:click="toggleOptionRetired({{ $index }})" type="button"
                                        class="rounded px-2 py-0.5 text-[11px] font-semibold transition {{ $isRetired ? 'bg-red-100 text-red-700 dark:bg-red-950 dark:text-red-300' : 'bg-amber-50 text-amber-700 hover:bg-amber-100 dark:bg-amber-950/50 dark:text-amber-300' }}"
                                        title="{{ $isRetired ? 'Un-retire option' : 'Retire option (flags existing products using it)' }}">
                                    {{ $isRetired ? 'Retired' : 'Retire' }}
                                </button>

                                @if (! $isOptUsed)
                                    <button wire:click="removeOption({{ $index }})" type="button" class="text-red-500 hover:text-red-700 p-1" title="Delete unused option">
                                        <svg class="size-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                    </button>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="px-4 py-6 text-center text-xs text-zinc-400">
                            No options added yet.
                        </div>
                    @endforelse
                </div>

                <!-- Add Option Form -->
                <form wire:submit="addOption" class="mt-4 pt-4 border-t border-zinc-100 dark:border-zinc-800 space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Option Label *</label>
                            <input wire:model.live.debounce.200ms="new_option_label" type="text" placeholder="e.g. Extra Large"
                                   class="mt-1 block w-full rounded-md border border-zinc-300 bg-white px-2.5 py-1.5 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            @error('new_option_label') <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Value (Slug) *</label>
                            <input wire:model="new_option_value" type="text" placeholder="e.g. xl"
                                   class="mt-1 block w-full rounded-md border border-zinc-300 bg-white px-2.5 py-1.5 text-xs font-mono text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            @error('new_option_value') <p class="mt-1 text-[11px] text-red-600">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <button type="submit" data-test="add-option-btn"
                            class="w-full rounded-md bg-zinc-900 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                        + Add Option
                    </button>
                </form>

                <div class="mt-4 flex justify-end">
                    <button type="button" wire:click="$set('showOptionsModal', false)"
                            class="rounded-lg border border-zinc-300 px-4 py-2 text-xs font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                        Done
                    </button>
                </div>
            </div>
        </div>
    @endif
</div>

