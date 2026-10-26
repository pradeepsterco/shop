<?php

namespace App\Livewire\Storefront;

use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\SellerStatus;
use App\Enums\VariantQcStatus;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Vertical;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.storefront')] #[Title('Browse Products - Khilauna')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $vertical = '';

    #[Url(except: [])]
    public array $brands = [];

    #[Url(except: '')]
    public ?string $min_price = null;

    #[Url(except: '')]
    public ?string $max_price = null;

    #[Url(except: '')]
    public ?string $min_discount = null;

    #[Url(except: false)]
    public bool $in_stock = false;

    #[Url(except: [])]
    public array $attrs = [];

    #[Url(except: 'featured')]
    public string $sort = 'featured';

    public function updatedCategory(): void
    {
        $this->vertical = '';
        $this->attrs = [];
        $this->resetPage();
    }

    public function updatedVertical(): void
    {
        $this->attrs = [];
        $this->resetPage();
    }

    public function updatedBrands(): void
    {
        $this->resetPage();
    }

    public function updatedMinPrice(): void
    {
        $this->resetPage();
    }

    public function updatedMaxPrice(): void
    {
        $this->resetPage();
    }

    public function updatedMinDiscount(): void
    {
        $this->resetPage();
    }

    public function updatedInStock(): void
    {
        $this->resetPage();
    }

    public function updatedAttrs(): void
    {
        $this->resetPage();
    }

    public function updatedSort(): void
    {
        $this->resetPage();
    }

    public function setCategory(string $slug): void
    {
        if ($this->category === $slug) {
            $this->category = '';
        } else {
            $this->category = $slug;
        }
        $this->vertical = '';
        $this->attrs = [];
        $this->resetPage();
    }

    public function setVertical(string $slug): void
    {
        if ($this->vertical === $slug) {
            $this->vertical = '';
        } else {
            $this->vertical = $slug;
        }
        $this->attrs = [];
        $this->resetPage();
    }

    public function setPricePreset(?int $min, ?int $max): void
    {
        $this->min_price = $min !== null ? (string) $min : null;
        $this->max_price = $max !== null ? (string) $max : null;
        $this->resetPage();
    }

    public function setDiscountPreset(?int $discount): void
    {
        $this->min_discount = $discount !== null ? (string) $discount : null;
        $this->resetPage();
    }

    public function toggleAttributeOption(string $code, string $val): void
    {
        $current = $this->attrs[$code] ?? [];
        if (! is_array($current)) {
            $current = [];
        }

        if (in_array($val, $current, true)) {
            $current = array_values(array_filter($current, fn ($v) => $v !== $val));
            if (empty($current)) {
                unset($this->attrs[$code]);
            } else {
                $this->attrs[$code] = $current;
            }
        } else {
            $current[] = $val;
            $this->attrs[$code] = $current;
        }

        $this->resetPage();
    }

    public function clearFilter(string $filterKey, ?string $subKey = null): void
    {
        if ($filterKey === 'category') {
            $this->category = '';
            $this->vertical = '';
            $this->attrs = [];
        } elseif ($filterKey === 'vertical') {
            $this->vertical = '';
            $this->attrs = [];
        } elseif ($filterKey === 'price') {
            $this->min_price = null;
            $this->max_price = null;
        } elseif ($filterKey === 'discount') {
            $this->min_discount = null;
        } elseif ($filterKey === 'in_stock') {
            $this->in_stock = false;
        } elseif ($filterKey === 'brand' && $subKey !== null) {
            $this->brands = array_values(array_filter($this->brands, fn ($b) => $b !== $subKey));
        } elseif ($filterKey === 'attr' && $subKey !== null) {
            unset($this->attrs[$subKey]);
        }
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->category = '';
        $this->vertical = '';
        $this->brands = [];
        $this->min_price = null;
        $this->max_price = null;
        $this->min_discount = null;
        $this->in_stock = false;
        $this->attrs = [];
        $this->sort = 'featured';
        $this->resetPage();
    }

    /**
     * Resolves the selected vertical if exactly ONE vertical is targeted.
     */
    #[Computed]
    public function selectedVertical(): ?Vertical
    {
        if (filled($this->vertical)) {
            return Vertical::where('slug', $this->vertical)->where('is_active', true)->first();
        }

        if (filled($this->category)) {
            $categoryModel = Category::where('slug', $this->category)->where('is_active', true)->first();
            if ($categoryModel) {
                $categoryIds = $this->getCategoryAndDescendantIds($categoryModel);
                $verticals = Vertical::whereIn('category_id', $categoryIds)->where('is_active', true)->get();
                if ($verticals->count() === 1) {
                    return $verticals->first();
                }
            }
        }

        return null;
    }

    /**
     * Returns variant attributes to show only when exactly ONE vertical is selected.
     *
     * @return Collection<int, mixed>
     */
    #[Computed]
    public function dynamicAttributes(): Collection
    {
        $vertical = $this->selectedVertical;
        if (! $vertical) {
            return collect();
        }

        return $vertical->attributes()
            ->where('is_variant', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Returns top-level categories with subcategories and verticals.
     *
     * @return Collection<int, Category>
     */
    #[Computed]
    public function rootCategories(): Collection
    {
        return Category::whereNull('parent_id')
            ->where('is_active', true)
            ->with(['children' => fn ($q) => $q->where('is_active', true)->with('verticals'), 'verticals'])
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Returns all approved brands that have products.
     *
     * @return Collection<int, Brand>
     */
    #[Computed]
    public function availableBrands(): Collection
    {
        return Brand::where('status', BrandStatus::Approved)
            ->whereHas('products', fn ($q) => $q->where('qc_status', ProductQcStatus::Approved)->whereNotNull('buy_box_listing_id'))
            ->orderBy('name')
            ->get();
    }

    /**
     * Paginated products matching all criteria.
     */
    #[Computed]
    public function products(): LengthAwarePaginator
    {
        $query = Product::query()
            ->where('qc_status', ProductQcStatus::Approved)
            ->whereNotNull('buy_box_listing_id')
            ->with([
                'brand',
                'images' => fn ($q) => $q->where('status', ProductImageStatus::Live)->orderBy('sort_order'),
                'buyBoxListing.sellerProfile',
                'buyBoxListing.variant',
            ]);

        // 1. Category & Vertical Filter
        if (filled($this->vertical)) {
            $query->whereHas('vertical', fn ($q) => $q->where('slug', $this->vertical));
        } elseif (filled($this->category)) {
            $categoryModel = Category::where('slug', $this->category)->where('is_active', true)->first();
            if ($categoryModel) {
                $categoryIds = $this->getCategoryAndDescendantIds($categoryModel);
                $query->whereHas('vertical', fn ($q) => $q->whereIn('category_id', $categoryIds));
            }
        }

        // 2. Brands Filter
        $selectedBrands = array_values(array_filter($this->brands, fn ($b) => filled($b)));
        if (! empty($selectedBrands)) {
            $query->whereHas('brand', fn ($q) => $q->whereIn('slug', $selectedBrands));
        }

        // 3. In Stock Filter (if checked, hide out of stock; otherwise show both)
        if ($this->in_stock) {
            $query->where('in_stock', true);
        }

        // 4. Price Filter (converted to paise in one place)
        $minPaise = is_numeric($this->min_price) && (float) $this->min_price > 0
            ? (int) round((float) $this->min_price * 100)
            : null;
        $maxPaise = is_numeric($this->max_price) && (float) $this->max_price > 0
            ? (int) round((float) $this->max_price * 100)
            : null;

        if ($minPaise !== null) {
            $query->where('min_price', '>=', $minPaise);
        }
        if ($maxPaise !== null) {
            $query->where('min_price', '<=', $maxPaise);
        }

        // 5. Discount Filter
        if (filled($this->min_discount) && is_numeric($this->min_discount)) {
            $query->where('discount_percent', '>=', (int) $this->min_discount);
        }

        // 6. Dynamic Variant Attribute Filters
        $vertical = $this->selectedVertical;
        if ($vertical && ! empty($this->attrs)) {
            $sanitizedAttrs = $this->sanitizeAttributeFilters($this->attrs, $vertical);

            if (! empty($sanitizedAttrs)) {
                $query->whereHas('variants', function (Builder $variantQuery) use ($sanitizedAttrs) {
                    $variantQuery->where('qc_status', VariantQcStatus::Approved)
                        ->whereNull('deleted_at')
                        ->whereHas('listings', function (Builder $listingQuery) {
                            $listingQuery->where('status', ListingStatus::Active)
                                ->whereNull('deleted_at')
                                ->whereHas('sellerProfile', function (Builder $sellerQuery) {
                                    $sellerQuery->where('status', SellerStatus::Approved);
                                });
                        });

                    // Same-variant requirement: AND across attributes, OR within each attribute
                    foreach ($sanitizedAttrs as $code => $values) {
                        $variantQuery->where(function (Builder $sub) use ($code, $values) {
                            foreach ($values as $val) {
                                $sub->orWhere("variant_attributes->{$code}", $val);
                            }
                        });
                    }
                });
            }
        }

        // 7. Sorting (always sort in_stock items first, then chosen sort)
        $sortWhitelist = [
            'price_asc' => function (Builder $q) {
                $q->orderByDesc('in_stock')->orderBy('min_price', 'asc')->orderByDesc('id');
            },
            'price_desc' => function (Builder $q) {
                $q->orderByDesc('in_stock')->orderBy('min_price', 'desc')->orderByDesc('id');
            },
            'discount_desc' => function (Builder $q) {
                $q->orderByDesc('in_stock')->orderByDesc('discount_percent')->orderByDesc('id');
            },
            'newest' => function (Builder $q) {
                $q->orderByDesc('in_stock')->orderByDesc('id');
            },
            'featured' => function (Builder $q) {
                $q->orderByDesc('in_stock')->orderByDesc('id');
            },
        ];

        $sortHandler = $sortWhitelist[$this->sort] ?? $sortWhitelist['featured'];
        $sortHandler($query);

        return $query->paginate(16);
    }

    /**
     * Recursively collect category and all its descendant category IDs.
     *
     * @return array<int, int>
     */
    protected function getCategoryAndDescendantIds(Category $rootCategory): array
    {
        $allIds = [$rootCategory->id];
        $currentLevelIds = [$rootCategory->id];

        while (! empty($currentLevelIds)) {
            $childIds = Category::whereIn('parent_id', $currentLevelIds)->pluck('id')->all();
            if (empty($childIds)) {
                break;
            }
            $allIds = array_merge($allIds, $childIds);
            $currentLevelIds = $childIds;
        }

        return array_values(array_unique($allIds));
    }

    /**
     * Whitelist and sanitize attribute filters against the selected vertical.
     *
     * @param array<string, mixed> $rawAttrs
     * @return array<string, array<int, string>>
     */
    protected function sanitizeAttributeFilters(array $rawAttrs, Vertical $vertical): array
    {
        if (count($rawAttrs) > 10) {
            $rawAttrs = array_slice($rawAttrs, 0, 10, true);
        }

        $validAttributes = $vertical->attributes()
            ->where('is_variant', true)
            ->get()
            ->keyBy('code');

        $sanitized = [];

        foreach ($rawAttrs as $rawCode => $rawValues) {
            if (! is_string($rawCode) || ! isset($validAttributes[$rawCode])) {
                continue;
            }

            $attribute = $validAttributes[$rawCode];
            $validOptionValues = collect($attribute->options ?? [])
                ->where('is_active', true)
                ->pluck('value')
                ->map(fn ($v) => (string) $v)
                ->all();

            $valuesArray = is_array($rawValues) ? $rawValues : [$rawValues];
            if (count($valuesArray) > 10) {
                $valuesArray = array_slice($valuesArray, 0, 10);
            }

            $matchedValues = [];
            foreach ($valuesArray as $val) {
                if (is_scalar($val) && in_array((string) $val, $validOptionValues, true)) {
                    $matchedValues[] = (string) $val;
                }
            }

            if (! empty($matchedValues)) {
                $sanitized[$attribute->code] = array_values(array_unique($matchedValues));
            }
        }

        return $sanitized;
    }
}; ?>

<div class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
    <!-- Breadcrumb & Top Section -->
    <div class="mb-6 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between border-b border-zinc-200 pb-5 dark:border-zinc-800">
        <div>
            <nav class="flex items-center gap-2 text-xs text-zinc-500 dark:text-zinc-400 mb-1">
                <a href="{{ route('products.index') }}" class="hover:text-zinc-800 dark:hover:text-zinc-200" wire:navigate>Catalog</a>
                @if ($category)
                    <span>/</span>
                    <span class="capitalize">{{ str_replace('-', ' ', $category) }}</span>
                @endif
                @if ($vertical)
                    <span>/</span>
                    <span class="capitalize font-medium text-zinc-800 dark:text-zinc-200">{{ str_replace('-', ' ', $vertical) }}</span>
                @endif
            </nav>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">
                @if ($vertical)
                    {{ ucwords(str_replace('-', ' ', $vertical)) }}
                @elseif ($category)
                    {{ ucwords(str_replace('-', ' ', $category)) }}
                @else
                    All Products
                @endif
                <span class="ml-2 text-sm font-normal text-zinc-500 dark:text-zinc-400">({{ $this->products->total() }} items)</span>
            </h1>
        </div>

        <!-- Sort Control -->
        <div class="flex items-center gap-3">
            <label for="sort-select" class="text-xs font-medium text-zinc-600 dark:text-zinc-400 whitespace-nowrap">Sort by:</label>
            <select
                id="sort-select"
                wire:model.live="sort"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm font-medium text-zinc-800 shadow-xs focus:border-amber-500 focus:outline-hidden focus:ring-2 focus:ring-amber-500/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200"
            >
                <option value="featured">Featured</option>
                <option value="price_asc">Price: Low to High</option>
                <option value="price_desc">Price: High to Low</option>
                <option value="discount_desc">Biggest Discount</option>
                <option value="newest">Newest Arrivals</option>
            </select>
        </div>
    </div>

    <!-- Active Filter Badges -->
    @php
        $hasActiveFilters = filled($this->category) || filled($this->vertical) || ! empty($this->brands) || filled($this->min_price) || filled($this->max_price) || filled($this->min_discount) || $this->in_stock || ! empty($this->attrs);
    @endphp

    @if ($hasActiveFilters)
        <div class="mb-6 flex flex-wrap items-center gap-2">
            <span class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Active filters:</span>

            @if ($category)
                <button
                    wire:click="clearFilter('category')"
                    class="inline-flex items-center gap-1 rounded-full border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-800 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                >
                    <span>Category: {{ ucwords(str_replace('-', ' ', $category)) }}</span>
                    <span class="text-zinc-400 hover:text-zinc-600">&times;</span>
                </button>
            @endif

            @if ($vertical)
                <button
                    wire:click="clearFilter('vertical')"
                    class="inline-flex items-center gap-1 rounded-full border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-800 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                >
                    <span>Vertical: {{ ucwords(str_replace('-', ' ', $vertical)) }}</span>
                    <span class="text-zinc-400 hover:text-zinc-600">&times;</span>
                </button>
            @endif

            @foreach ($brands as $b)
                <button
                    wire:click="clearFilter('brand', '{{ $b }}')"
                    class="inline-flex items-center gap-1 rounded-full border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-800 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                >
                    <span>Brand: {{ strtoupper($b) }}</span>
                    <span class="text-zinc-400 hover:text-zinc-600">&times;</span>
                </button>
            @endforeach

            @if (filled($min_price) || filled($max_price))
                <button
                    wire:click="clearFilter('price')"
                    class="inline-flex items-center gap-1 rounded-full border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-800 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                >
                    <span>Price: ₹{{ $min_price ?? '0' }} - ₹{{ $max_price ?? 'Any' }}</span>
                    <span class="text-zinc-400 hover:text-zinc-600">&times;</span>
                </button>
            @endif

            @if (filled($min_discount))
                <button
                    wire:click="clearFilter('discount')"
                    class="inline-flex items-center gap-1 rounded-full border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-800 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                >
                    <span>{{ $min_discount }}%+ Discount</span>
                    <span class="text-zinc-400 hover:text-zinc-600">&times;</span>
                </button>
            @endif

            @if ($in_stock)
                <button
                    wire:click="clearFilter('in_stock')"
                    class="inline-flex items-center gap-1 rounded-full border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-800 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                >
                    <span>In Stock Only</span>
                    <span class="text-zinc-400 hover:text-zinc-600">&times;</span>
                </button>
            @endif

            @foreach ($attrs as $code => $values)
                @if (! empty($values))
                    <button
                        wire:click="clearFilter('attr', '{{ $code }}')"
                        class="inline-flex items-center gap-1 rounded-full border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-800 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200"
                    >
                        <span>{{ ucwords($code) }}: {{ implode(', ', (array) $values) }}</span>
                        <span class="text-zinc-400 hover:text-zinc-600">&times;</span>
                    </button>
                @endif
            @endforeach

            <button
                wire:click="resetFilters"
                class="text-xs font-semibold text-amber-600 hover:text-amber-700 dark:text-amber-400 underline ml-2"
            >
                Clear all
            </button>
        </div>
    @endif

    <div class="grid grid-cols-1 gap-8 lg:grid-cols-4">
        <!-- Sidebar Filters -->
        <aside class="space-y-6">
            <!-- Categories Filter -->
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-white uppercase mb-3">Categories</h3>
                <ul class="space-y-2 text-sm">
                    <li>
                        <button
                            type="button"
                            wire:click="setCategory('')"
                            class="w-full text-left font-medium {{ empty($category) && empty($vertical) ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-600 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
                        >
                            All Categories
                        </button>
                    </li>
                    @foreach ($this->rootCategories as $cat)
                        <li class="space-y-1">
                            <button
                                type="button"
                                wire:click="setCategory('{{ $cat->slug }}')"
                                class="flex items-center justify-between w-full text-left font-medium {{ $category === $cat->slug ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-700 hover:text-zinc-900 dark:text-zinc-300 dark:hover:text-zinc-100' }}"
                            >
                                <span>{{ $cat->name }}</span>
                                @if ($cat->children->isNotEmpty())
                                    <svg class="h-3.5 w-3.5 opacity-60" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                                    </svg>
                                @endif
                            </button>

                            <!-- Subcategories / Verticals if category selected or open -->
                            @if ($category === $cat->slug || empty($category))
                                <ul class="pl-3 border-l border-zinc-200 dark:border-zinc-800 space-y-1 text-xs mt-1">
                                    @foreach ($cat->children as $subCat)
                                        @foreach ($subCat->verticals as $v)
                                            <li>
                                                <button
                                                    type="button"
                                                    wire:click="setVertical('{{ $v->slug }}')"
                                                    class="w-full text-left py-0.5 {{ $vertical === $v->slug ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
                                                >
                                                    {{ $v->name }}
                                                </button>
                                            </li>
                                        @endforeach
                                    @endforeach
                                    @foreach ($cat->verticals as $v)
                                        <li>
                                            <button
                                                type="button"
                                                wire:click="setVertical('{{ $v->slug }}')"
                                                class="w-full text-left py-0.5 {{ $vertical === $v->slug ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' }}"
                                            >
                                                {{ $v->name }}
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @endif
                        </li>
                    @endforeach
                </ul>
            </div>

            <!-- Price Range Filter -->
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-white uppercase mb-3">Price Range</h3>

                <!-- Presets -->
                <div class="space-y-1.5 text-xs mb-4">
                    <button
                        type="button"
                        wire:click="setPricePreset(0, 500)"
                        class="block w-full text-left px-2 py-1 rounded-md {{ $min_price === '0' && $max_price === '500' ? 'bg-amber-50 text-amber-700 font-medium dark:bg-amber-950 dark:text-amber-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}"
                    >
                        Under ₹500
                    </button>
                    <button
                        type="button"
                        wire:click="setPricePreset(500, 1000)"
                        class="block w-full text-left px-2 py-1 rounded-md {{ $min_price === '500' && $max_price === '1000' ? 'bg-amber-50 text-amber-700 font-medium dark:bg-amber-950 dark:text-amber-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}"
                    >
                        ₹500 - ₹1,000
                    </button>
                    <button
                        type="button"
                        wire:click="setPricePreset(1000, 2500)"
                        class="block w-full text-left px-2 py-1 rounded-md {{ $min_price === '1000' && $max_price === '2500' ? 'bg-amber-50 text-amber-700 font-medium dark:bg-amber-950 dark:text-amber-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}"
                    >
                        ₹1,000 - ₹2,500
                    </button>
                    <button
                        type="button"
                        wire:click="setPricePreset(2500, 5000)"
                        class="block w-full text-left px-2 py-1 rounded-md {{ $min_price === '2500' && $max_price === '5000' ? 'bg-amber-50 text-amber-700 font-medium dark:bg-amber-950 dark:text-amber-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}"
                    >
                        ₹2,500 - ₹5,000
                    </button>
                    <button
                        type="button"
                        wire:click="setPricePreset(5000, null)"
                        class="block w-full text-left px-2 py-1 rounded-md {{ $min_price === '5000' && $max_price === null ? 'bg-amber-50 text-amber-700 font-medium dark:bg-amber-950 dark:text-amber-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}"
                    >
                        Above ₹5,000
                    </button>
                </div>

                <!-- Custom Inputs (In Rupees) -->
                <div class="grid grid-cols-2 gap-2 text-xs">
                    <div>
                        <label for="custom-min-price" class="text-zinc-500 dark:text-zinc-400">Min (₹)</label>
                        <input
                            id="custom-min-price"
                            type="number"
                            min="0"
                            placeholder="0"
                            wire:model.live.debounce.400ms="min_price"
                            class="w-full rounded-md border border-zinc-300 bg-white px-2.5 py-1 text-xs text-zinc-900 shadow-2xs focus:border-amber-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                    <div>
                        <label for="custom-max-price" class="text-zinc-500 dark:text-zinc-400">Max (₹)</label>
                        <input
                            id="custom-max-price"
                            type="number"
                            min="0"
                            placeholder="Max"
                            wire:model.live.debounce.400ms="max_price"
                            class="w-full rounded-md border border-zinc-300 bg-white px-2.5 py-1 text-xs text-zinc-900 shadow-2xs focus:border-amber-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                        />
                    </div>
                </div>
            </div>

            <!-- Discount Filter -->
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-white uppercase mb-3">Discount</h3>
                <div class="space-y-1.5 text-xs">
                    <button
                        type="button"
                        wire:click="setDiscountPreset(null)"
                        class="block w-full text-left px-2 py-1 rounded-md {{ empty($min_discount) ? 'bg-amber-50 text-amber-700 font-medium dark:bg-amber-950 dark:text-amber-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}"
                    >
                        All Discounts
                    </button>
                    @foreach ([10, 20, 30, 50] as $disc)
                        <button
                            type="button"
                            wire:click="setDiscountPreset({{ $disc }})"
                            class="block w-full text-left px-2 py-1 rounded-md {{ $min_discount == (string) $disc ? 'bg-amber-50 text-amber-700 font-medium dark:bg-amber-950 dark:text-amber-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}"
                        >
                            {{ $disc }}% or more
                        </button>
                    @endforeach
                </div>
            </div>

            <!-- Brands Filter -->
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-white uppercase mb-3">Brands</h3>
                <div class="max-h-48 overflow-y-auto space-y-2 pr-1">
                    @foreach ($this->availableBrands as $brandModel)
                        <label class="flex items-center gap-2.5 text-xs text-zinc-700 dark:text-zinc-300 cursor-pointer hover:text-zinc-900 dark:hover:text-white">
                            <input
                                type="checkbox"
                                value="{{ $brandModel->slug }}"
                                wire:model.live="brands"
                                class="rounded border-zinc-300 text-amber-600 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800"
                            />
                            <span>{{ $brandModel->name }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <!-- Availability (In Stock Only) -->
            <div class="rounded-xl border border-zinc-200 bg-white p-5 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-white uppercase mb-3">Availability</h3>
                <label class="flex items-center gap-2.5 text-xs text-zinc-700 dark:text-zinc-300 cursor-pointer">
                    <input
                        type="checkbox"
                        wire:model.live="in_stock"
                        class="rounded border-zinc-300 text-amber-600 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800"
                    />
                    <span>Exclude out of stock items</span>
                </label>
            </div>

            <!-- Dynamic Variant Attributes (Shown ONLY when exactly ONE vertical is selected) -->
            @if ($this->selectedVertical && $this->dynamicAttributes->isNotEmpty())
                <div class="rounded-xl border border-amber-200 bg-amber-50/40 p-5 shadow-xs dark:border-amber-900/50 dark:bg-zinc-900">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold tracking-tight text-zinc-900 dark:text-white uppercase">
                            {{ $this->selectedVertical->name }} Options
                        </h3>
                        <span class="text-[10px] font-medium text-amber-600 dark:text-amber-400 bg-amber-100 dark:bg-amber-950 px-1.5 py-0.5 rounded">Vertical</span>
                    </div>

                    <div class="space-y-4">
                        @foreach ($this->dynamicAttributes as $attr)
                            <div class="border-t border-zinc-200/80 pt-3 dark:border-zinc-800 first:border-0 first:pt-0">
                                <h4 class="text-xs font-semibold text-zinc-800 dark:text-zinc-200 mb-2">{{ $attr->name }}</h4>
                                <div class="space-y-1.5 text-xs">
                                    @foreach ($attr->options ?? [] as $opt)
                                        @if ($opt['is_active'] ?? true)
                                            @php
                                                $isSelected = in_array($opt['value'], (array) ($attrs[$attr->code] ?? []), true);
                                            @endphp
                                            <label class="flex items-center gap-2 text-zinc-700 dark:text-zinc-300 cursor-pointer hover:text-zinc-900">
                                                <input
                                                    type="checkbox"
                                                    wire:click="toggleAttributeOption('{{ $attr->code }}', '{{ $opt['value'] }}')"
                                                    @checked($isSelected)
                                                    class="rounded border-zinc-300 text-amber-600 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800"
                                                />
                                                <span>{{ $opt['label'] }}</span>
                                            </label>
                                        @endif
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </aside>

        <!-- Product Cards Grid -->
        <main class="lg:col-span-3">
            @if ($this->products->isEmpty())
                <div class="flex flex-col items-center justify-center rounded-2xl border border-dashed border-zinc-300 bg-white p-12 text-center dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="flex h-12 w-12 items-center justify-center rounded-full bg-zinc-100 text-zinc-400 dark:bg-zinc-800 dark:text-zinc-500 mb-3">
                        <svg class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                        </svg>
                    </div>
                    <h3 class="text-base font-semibold text-zinc-900 dark:text-white">No products found</h3>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400 max-w-sm">
                        We couldn't find any products matching your current combination of filters. Try clearing some filters.
                    </p>
                    <button
                        type="button"
                        wire:click="resetFilters"
                        class="mt-4 inline-flex items-center rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-amber-600 transition"
                    >
                        Clear all filters
                    </button>
                </div>
            @else
                <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                    @foreach ($this->products as $product)
                        @php
                            $primaryImg = $product->images->first(fn ($img) => $img->is_primary && $img->status === ProductImageStatus::Live)
                                ?? $product->images->first();
                            $imgUrl = $primaryImg ? $primaryImg->url() : asset('storage/products/placeholder.svg');
                            $buyBox = $product->buyBoxListing;
                            $variantUpid = $buyBox?->variant?->upid;
                        @endphp

                        <div class="group relative flex flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white transition hover:shadow-md dark:border-zinc-800 dark:bg-zinc-900">
                            <!-- Clickable Card Link -->
                            <a
                                href="{{ $variantUpid ? route('product.show', ['slug' => $product->slug, 'upid' => $variantUpid]) : '#' }}"
                                class="flex flex-col h-full"
                                wire:navigate
                            >
                                <!-- Image Container -->
                                <div class="relative aspect-square w-full overflow-hidden bg-zinc-100 dark:bg-zinc-800">
                                    <img
                                        src="{{ $imgUrl }}"
                                        alt="{{ $product->title }}"
                                        class="h-full w-full object-cover object-center transition duration-300 group-hover:scale-105"
                                        loading="lazy"
                                    />

                                    <!-- Badges -->
                                    <div class="absolute top-2.5 left-2.5 flex flex-col gap-1 items-start">
                                        @if (! $product->in_stock)
                                            <span class="inline-flex items-center rounded-md bg-zinc-900/90 px-2 py-0.5 text-[11px] font-semibold text-white backdrop-blur-xs">
                                                Out of stock
                                            </span>
                                        @elseif ($product->discount_percent && $product->discount_percent > 0)
                                            <span class="inline-flex items-center rounded-md bg-emerald-600 px-2 py-0.5 text-[11px] font-semibold text-white shadow-2xs">
                                                {{ $product->discount_percent }}% OFF
                                            </span>
                                        @endif
                                    </div>
                                </div>

                                <!-- Card Content -->
                                <div class="flex flex-1 flex-col p-4">
                                    <!-- Brand Name -->
                                    <p class="text-[11px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-1">
                                        {{ $product->brand?->name ?? 'Brand' }}
                                    </p>

                                    <!-- Product Title -->
                                    <h2 class="text-sm font-medium text-zinc-900 dark:text-white line-clamp-2 leading-snug group-hover:text-amber-600 dark:group-hover:text-amber-400 transition mb-2">
                                        {{ $product->title }}
                                    </h2>

                                    <!-- Sold By Merchant -->
                                    @if ($buyBox?->sellerProfile)
                                        <p class="text-[11px] text-zinc-500 dark:text-zinc-400 mb-3 truncate">
                                            Sold by: <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $buyBox->sellerProfile->store_name }}</span>
                                        </p>
                                    @endif

                                    <!-- Price Container -->
                                    <div class="mt-auto flex items-baseline gap-2 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                                        <span class="text-lg font-bold text-zinc-900 dark:text-white">
                                            ₹{{ number_format((int) $product->min_price / 100) }}
                                        </span>
                                        @if ($product->buy_box_mrp && $product->buy_box_mrp > $product->min_price)
                                            <span class="text-xs text-zinc-400 line-through">
                                                ₹{{ number_format((int) $product->buy_box_mrp / 100) }}
                                            </span>
                                        @endif
                                    </div>
                                </div>
                            </a>
                        </div>
                    @endforeach
                </div>

                <!-- Pagination -->
                <div class="mt-8">
                    {{ $this->products->links() }}
                </div>
            @endif
        </main>
    </div>
</div>
