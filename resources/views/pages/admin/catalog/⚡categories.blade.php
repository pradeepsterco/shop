<?php

use App\Models\Category;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Manage Categories')] class extends Component {
    public string $name = '';
    public ?int $parent_id = null;
    public int $sort_order = 0;
    public bool $showCreateModal = false;
    public ?int $editingCategoryId = null;

    public string $search = '';

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:100'],
            'parent_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
                function ($attribute, $value, $fail) {
                    if ($value) {
                        $parent = Category::find($value);
                        if ($parent && ! $parent->canHaveChildren()) {
                            $fail('Cannot add child categories to a category that has verticals assigned.');
                        }
                    }
                },
            ],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ];
    }

    public function openCreateModal(?int $parentId = null): void
    {
        $this->authorize('catalog.categories');

        $this->reset(['name', 'editingCategoryId']);
        $this->parent_id = $parentId;
        $this->sort_order = 0;
        $this->showCreateModal = true;
    }

    public function openEditModal(int $id): void
    {
        $this->authorize('catalog.categories');

        $category = Category::findOrFail($id);
        $this->editingCategoryId = $category->id;
        $this->name = $category->name;
        $this->parent_id = $category->parent_id;
        $this->sort_order = $category->sort_order;
        $this->showCreateModal = true;
    }

    public function saveCategory(): void
    {
        $this->authorize('catalog.categories');

        $this->validate();

        if ($this->editingCategoryId) {
            $category = Category::findOrFail($this->editingCategoryId);

            // Prevent assigning parent as itself or its descendants
            if ($this->parent_id === $category->id) {
                $this->addError('parent_id', 'A category cannot be its own parent.');

                return;
            }

            $category->update([
                'name' => $this->name,
                'parent_id' => $this->parent_id,
                'sort_order' => $this->sort_order,
                'slug' => Category::generateUniqueSlug($this->name, $category->id),
            ]);

            session()->flash('status', "Category [{$category->name}] updated.");
        } else {
            $category = Category::create([
                'name' => $this->name,
                'parent_id' => $this->parent_id,
                'sort_order' => $this->sort_order,
            ]);

            session()->flash('status', "Category [{$category->name}] created.");
        }

        $this->showCreateModal = false;
        $this->reset(['name', 'parent_id', 'sort_order', 'editingCategoryId']);
    }

    public function toggleActive(int $id): void
    {
        $this->authorize('catalog.categories');

        $category = Category::with('children')->findOrFail($id);

        if ($category->is_active) {
            // Deactivating cascades down to children
            $category->deactivateWithChildren();
            session()->flash('status', "Category [{$category->name}] and all sub-categories were deactivated.");
        } else {
            $category->update(['is_active' => true]);
            session()->flash('status', "Category [{$category->name}] activated.");
        }
    }

    /**
     * Flatten the hierarchical tree with depth indicator.
     *
     * @return array<int, array{category: Category, depth: int, breadcrumb: string, has_verticals: bool, can_have_children: bool}>
     */
    public function getCategoriesProperty(): array
    {
        $rootQuery = Category::with(['children.children.children', 'verticals'])
            ->whereNull('parent_id')
            ->orderBy('sort_order');

        if ($this->search !== '') {
            $matchingIds = Category::where('name', 'like', "%{$this->search}%")
                ->orWhere('slug', 'like', "%{$this->search}%")
                ->pluck('id')
                ->all();

            $flat = [];
            $categories = Category::with(['parent', 'verticals'])
                ->whereIn('id', $matchingIds)
                ->orderBy('sort_order')
                ->get();

            foreach ($categories as $cat) {
                $flat[] = [
                    'category' => $cat,
                    'depth' => count($cat->breadcrumbs()) - 1,
                    'breadcrumb' => $cat->breadcrumbString(),
                    'has_verticals' => $cat->verticals->isNotEmpty(),
                    'can_have_children' => $cat->canHaveChildren(),
                ];
            }

            return $flat;
        }

        $flat = [];
        $traverse = function ($category, $depth = 0) use (&$traverse, &$flat) {
            $flat[] = [
                'category' => $category,
                'depth' => $depth,
                'breadcrumb' => $category->breadcrumbString(),
                'has_verticals' => $category->verticals->isNotEmpty(),
                'can_have_children' => $category->canHaveChildren(),
            ];

            foreach ($category->children as $child) {
                $traverse($child, $depth + 1);
            }
        };

        foreach ($rootQuery->get() as $root) {
            $traverse($root, 0);
        }

        return $flat;
    }

    /**
     * Eligible parent categories (only those that do NOT have verticals assigned).
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Category>
     */
    public function getEligibleParentsProperty()
    {
        return Category::whereDoesntHave('verticals')
            ->when($this->editingCategoryId, fn ($q) => $q->where('id', '!=', $this->editingCategoryId))
            ->orderBy('name')
            ->get();
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Category Hierarchy</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Organize catalog taxonomies. Leaf categories link to Verticals. Deactivate instead of deleting.
            </p>
        </div>

        <div>
            <button wire:click="openCreateModal(null)" type="button" data-test="create-root-category-btn"
                    class="inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                </svg>
                <span>Add Root Category</span>
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
            <input wire:model.live.debounce.300ms="search" type="text" placeholder="Search categories..."
                   class="block w-full rounded-lg border border-zinc-300 bg-white py-2 pl-9 pr-3 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
            <svg class="pointer-events-none absolute left-3 top-2.5 size-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>
    </div>

    <!-- Tree Table -->
    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold uppercase tracking-wider text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400">
                    <tr>
                        <th class="px-6 py-3.5">Category Name & Hierarchy</th>
                        <th class="px-6 py-3.5">Slug</th>
                        <th class="px-6 py-3.5 text-center">Sort</th>
                        <th class="px-6 py-3.5 text-center">Type</th>
                        <th class="px-6 py-3.5 text-center">Status</th>
                        <th class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($this->categories as $row)
                        @php
                            $cat = $row['category'];
                            $depth = $row['depth'];
                        @endphp
                        <tr wire:key="cat-{{ $cat->id }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50 transition">
                            <td class="px-6 py-3">
                                <div class="flex items-center gap-2" style="padding-left: {{ $depth * 1.75 }}rem">
                                    @if ($depth > 0)
                                        <span class="text-zinc-300 dark:text-zinc-600 font-mono">└──</span>
                                    @endif
                                    <div>
                                        <div class="font-medium text-zinc-900 dark:text-white flex items-center gap-2">
                                            <span>{{ $cat->name }}</span>
                                            @if ($row['has_verticals'])
                                                <span class="inline-flex items-center rounded-sm bg-purple-50 px-1.5 py-0.5 text-[10px] font-semibold text-purple-700 dark:bg-purple-950/50 dark:text-purple-300 border border-purple-200 dark:border-purple-800">
                                                    Vertical Leaf
                                                </span>
                                            @endif
                                        </div>
                                        <div class="text-[11px] text-zinc-400">
                                            {{ $row['breadcrumb'] }}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-3 font-mono text-xs text-zinc-500">
                                {{ $cat->slug }}
                            </td>
                            <td class="px-6 py-3 text-center text-xs text-zinc-500">
                                {{ $cat->sort_order }}
                            </td>
                            <td class="px-6 py-3 text-center">
                                @if ($row['has_verticals'])
                                    <span class="text-xs text-purple-600 dark:text-purple-400 font-medium">Has Verticals</span>
                                @elseif ($cat->children->isNotEmpty())
                                    <span class="text-xs text-zinc-500">Parent ({{ $cat->children->count() }})</span>
                                @else
                                    <span class="text-xs text-emerald-600 dark:text-emerald-400">Ready for Vertical</span>
                                @endif
                            </td>
                            <td class="px-6 py-3 text-center">
                                <button wire:click="toggleActive({{ $cat->id }})" type="button"
                                        class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold cursor-pointer transition {{ $cat->is_active ? 'bg-green-50 text-green-700 dark:bg-green-950/50 dark:text-green-400 hover:bg-green-100' : 'bg-zinc-100 text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400 hover:bg-zinc-200' }}">
                                    {{ $cat->is_active ? 'Active' : 'Inactive' }}
                                </button>
                            </td>
                            <td class="px-6 py-3 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    @if ($row['can_have_children'])
                                        <button wire:click="openCreateModal({{ $cat->id }})" type="button" title="Add subcategory"
                                                class="rounded-md p-1.5 text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:hover:bg-zinc-800 dark:hover:text-white">
                                            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                                            </svg>
                                        </button>
                                    @endif
                                    <button wire:click="openEditModal({{ $cat->id }})" type="button" title="Edit category"
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
                            <td colspan="6" class="px-6 py-12 text-center text-sm text-zinc-400">
                                No categories found. Click "Add Root Category" to begin your catalog tree.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <!-- Create / Edit Modal -->
    @if ($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="$set('showCreateModal', false)" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-lg rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between pb-4 border-b border-zinc-100 dark:border-zinc-800">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">
                        {{ $editingCategoryId ? 'Edit Category' : ($parent_id ? 'Add Subcategory' : 'Add Root Category') }}
                    </h3>
                    <button wire:click="$set('showCreateModal', false)" type="button" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form wire:submit="saveCategory" class="mt-4 space-y-4">
                    <div>
                        <label for="cat-name" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Category Name *</label>
                        <input wire:model="name" type="text" id="cat-name" required placeholder="e.g. Topwear or Mobiles"
                               class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('name') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="cat-parent" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Parent Category</label>
                        <select wire:model="parent_id" id="cat-parent"
                                class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                            <option value="">— None (Root Category) —</option>
                            @foreach ($this->eligibleParents as $p)
                                <option value="{{ $p->id }}">{{ $p->breadcrumbString() }}</option>
                            @endforeach
                        </select>
                        <p class="mt-1 text-xs text-zinc-400">Categories that already contain verticals cannot have subcategories.</p>
                        @error('parent_id') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="cat-sort" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Sort Order</label>
                        <input wire:model="sort_order" type="number" min="0" max="9999" id="cat-sort"
                               class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('sort_order') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="button" wire:click="$set('showCreateModal', false)" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                            Cancel
                        </button>
                        <button type="submit" data-test="submit-category-btn" class="rounded-lg bg-zinc-900 px-4 py-2 text-sm font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                            {{ $editingCategoryId ? 'Update Category' : 'Create Category' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

