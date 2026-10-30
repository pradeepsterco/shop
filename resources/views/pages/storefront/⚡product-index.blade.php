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
use App\Search\Contracts\ProductSearchInterface;
use App\Search\DTOs\SearchResult;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Renderless;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.storefront')] #[Title('Browse Products')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $q = '';

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

    public function updatedQ(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->q = '';
        $this->resetPage();
    }

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
        return $this->resolveVertical($this->category, $this->vertical);
    }

    /**
     * Returns variant attributes to show only when exactly ONE vertical is selected.
     *
     * @return Collection<int, mixed>
     */
    #[Computed]
    public function dynamicAttributes(): Collection
    {
        return $this->attributesFor($this->selectedVertical);
    }

    /**
     * The single vertical targeted by a category/vertical pair, if exactly one.
     */
    protected function resolveVertical(string $category, string $vertical): ?Vertical
    {
        if (filled($vertical)) {
            return Vertical::where('slug', $vertical)->where('is_active', true)->first();
        }

        if (filled($category)) {
            $categoryModel = Category::where('slug', $category)->where('is_active', true)->first();
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
     * @return Collection<int, mixed>
     */
    protected function attributesFor(?Vertical $vertical): Collection
    {
        if (! $vertical) {
            return collect();
        }

        return $vertical->attributes()
            ->where('is_variant', true)
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Sort options, shared by the desktop select and the mobile sort sheet.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function sortOptions(): array
    {
        return [
            'featured' => $this->searchResult ? 'Relevance' : 'Featured',
            'price_asc' => 'Price: Low to High',
            'price_desc' => 'Price: High to Low',
            'discount_desc' => 'Biggest Discount',
            'newest' => 'Newest Arrivals',
        ];
    }

    /**
     * Price presets in rupees, shared by the desktop sidebar and the mobile filter sheet.
     *
     * @return array<int, array{label: string, min: int, max: ?int}>
     */
    #[Computed]
    public function pricePresets(): array
    {
        return [
            ['label' => 'Under ₹500', 'min' => 0, 'max' => 500],
            ['label' => '₹500 - ₹1,000', 'min' => 500, 'max' => 1000],
            ['label' => '₹1,000 - ₹2,500', 'min' => 1000, 'max' => 2500],
            ['label' => '₹2,500 - ₹5,000', 'min' => 2500, 'max' => 5000],
            ['label' => 'Above ₹5,000', 'min' => 5000, 'max' => null],
        ];
    }

    /**
     * @return array<int, int>
     */
    #[Computed]
    public function discountPresets(): array
    {
        return [10, 20, 30, 50];
    }

    /**
     * Number of active filters, shown on the mobile Filter button.
     */
    #[Computed]
    public function activeFilterCount(): int
    {
        $filters = $this->normalizeFilters($this->filterState());

        return (int) filled($filters['category'])
            + (int) filled($filters['vertical'])
            + count($filters['brands'])
            + (int) (filled($filters['min_price']) || filled($filters['max_price']))
            + (int) filled($filters['min_discount'])
            + (int) $filters['in_stock']
            + array_sum(array_map('count', $filters['attrs']));
    }

    /**
     * Mobile filter sheet: product count and variant filters for a draft,
     * without applying it (no re-render, URL unchanged).
     *
     * @param  array<string, mixed>  $filters
     * @return array{count: int, attributes: array<int, array{code: string, name: string, options: array<int, array{value: string, label: string}>}>}
     */
    #[Renderless]
    public function previewFilters(array $filters): array
    {
        $filters = $this->normalizeFilters($filters);
        $vertical = $this->resolveVertical($filters['category'], $filters['vertical']);

        return [
            'count' => $this->filteredQuery($filters)->count(),
            'attributes' => $this->attributesFor($vertical)
                ->map(fn ($attr) => [
                    'code' => (string) $attr->code,
                    'name' => (string) $attr->name,
                    'options' => collect($attr->options ?? [])
                        ->filter(fn ($opt) => $opt['is_active'] ?? true)
                        ->map(fn ($opt) => ['value' => (string) $opt['value'], 'label' => (string) $opt['label']])
                        ->values()
                        ->all(),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * Mobile filter sheet: apply a draft in one request.
     *
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(array $filters): void
    {
        $filters = $this->normalizeFilters($filters);

        $this->category = $filters['category'];
        $this->vertical = $filters['vertical'];
        $this->brands = $filters['brands'];
        $this->min_price = $filters['min_price'];
        $this->max_price = $filters['max_price'];
        $this->min_discount = $filters['min_discount'];
        $this->in_stock = $filters['in_stock'];
        $this->attrs = $filters['attrs'];
        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    protected function filterState(): array
    {
        return [
            'category' => $this->category,
            'vertical' => $this->vertical,
            'brands' => $this->brands,
            'min_price' => $this->min_price,
            'max_price' => $this->max_price,
            'min_discount' => $this->min_discount,
            'in_stock' => $this->in_stock,
            'attrs' => $this->attrs,
        ];
    }

    /**
     * Coerce filters (from the URL or the mobile sheet) into a known shape.
     * Attribute codes and values are checked against the vertical later.
     *
     * @param  array<string, mixed>  $input
     * @return array{category: string, vertical: string, brands: array<int, string>, min_price: ?string, max_price: ?string, min_discount: ?string, in_stock: bool, attrs: array<string, array<int, string>>}
     */
    protected function normalizeFilters(array $input): array
    {
        $text = fn ($value) => is_string($value) ? trim($value) : '';
        $amount = fn ($value) => (is_string($value) || is_int($value) || is_float($value)) && is_numeric($value) && (float) $value >= 0
            ? (string) $value
            : null;
        $list = fn ($values) => array_values(array_unique(array_filter(
            array_map($text, array_slice(is_array($values) ? $values : [], 0, 50)),
            fn (string $value) => $value !== '',
        )));

        $attrs = [];
        foreach (array_slice(is_array($input['attrs'] ?? null) ? $input['attrs'] : [], 0, 10, true) as $code => $values) {
            $values = array_slice($list($values), 0, 10);
            if (is_string($code) && $values !== []) {
                $attrs[$code] = $values;
            }
        }

        $discount = $amount($input['min_discount'] ?? null);

        return [
            'category' => $text($input['category'] ?? ''),
            'vertical' => $text($input['vertical'] ?? ''),
            'brands' => $list($input['brands'] ?? []),
            'min_price' => $amount($input['min_price'] ?? null),
            'max_price' => $amount($input['max_price'] ?? null),
            'min_discount' => $discount !== null && (float) $discount > 0 ? $discount : null,
            'in_stock' => filter_var($input['in_stock'] ?? false, FILTER_VALIDATE_BOOLEAN),
            'attrs' => $attrs,
        ];
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
        $searchResult = $this->searchResult;

        return Brand::where('status', BrandStatus::Approved)
            ->whereHas('products', function ($q) use ($searchResult) {
                $q->where('qc_status', ProductQcStatus::Approved)->whereNotNull('buy_box_listing_id');

                // While searching, only offer brands that have matching products
                if ($searchResult) {
                    $q->whereIntegerInRaw('products.id', $searchResult->productIds);
                }
            })
            ->orderBy('name')
            ->get();
    }

    /**
     * Search matches for the query (ordered product IDs), or null when not searching.
     */
    #[Computed]
    public function searchResult(): ?SearchResult
    {
        if (blank(trim($this->q))) {
            return null;
        }

        return app(ProductSearchInterface::class)->search($this->q);
    }

    /**
     * Paginated products matching all criteria.
     */
    #[Computed]
    public function products(): LengthAwarePaginator
    {
        $query = $this->filteredQuery($this->normalizeFilters($this->filterState()))
            ->with([
                'brand',
                'images' => fn ($q) => $q->where('status', ProductImageStatus::Live)->orderBy('sort_order'),
                'buyBoxListing.sellerProfile',
                'buyBoxListing.variant',
            ]);

        $searchResult = $this->searchResult;

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
            'featured' => function (Builder $q) use ($searchResult) {
                $q->orderByDesc('in_stock');

                // While searching, "featured" is relevance: the engine's order
                if ($searchResult && ! $searchResult->isEmpty()) {
                    $positions = collect($searchResult->productIds)
                        ->map(fn (int $id, int $position) => 'WHEN '.(int) $id.' THEN '.$position)
                        ->implode(' ');
                    $q->orderByRaw("CASE id {$positions} END");
                }

                $q->orderByDesc('id');
            },
        ];

        $sortHandler = $sortWhitelist[$this->sort] ?? $sortWhitelist['featured'];
        $sortHandler($query);

        return $query->paginate(16);
    }

    /**
     * Listed products matching the search and the given filters (no sorting or eager loads).
     * Used by the product grid and by the mobile filter sheet's live count.
     *
     * @param  array{category: string, vertical: string, brands: array<int, string>, min_price: ?string, max_price: ?string, min_discount: ?string, in_stock: bool, attrs: array<string, array<int, string>>}  $filters
     * @return Builder<Product>
     */
    protected function filteredQuery(array $filters): Builder
    {
        $query = Product::query()
            ->where('qc_status', ProductQcStatus::Approved)
            ->whereNotNull('buy_box_listing_id');

        // 0. Search: restrict to matches (price, stock and filters below stay live from the database)
        $searchResult = $this->searchResult;
        if ($searchResult) {
            $query->whereIntegerInRaw('id', $searchResult->productIds);
        }

        // 1. Category & Vertical Filter
        if (filled($filters['vertical'])) {
            $query->whereHas('vertical', fn ($q) => $q->where('slug', $filters['vertical']));
        } elseif (filled($filters['category'])) {
            $categoryModel = Category::where('slug', $filters['category'])->where('is_active', true)->first();
            if ($categoryModel) {
                $categoryIds = $this->getCategoryAndDescendantIds($categoryModel);
                $query->whereHas('vertical', fn ($q) => $q->whereIn('category_id', $categoryIds));
            }
        }

        // 2. Brands Filter
        if ($filters['brands'] !== []) {
            $query->whereHas('brand', fn ($q) => $q->whereIn('slug', $filters['brands']));
        }

        // 3. In Stock Filter (if checked, hide out of stock; otherwise show both)
        if ($filters['in_stock']) {
            $query->where('in_stock', true);
        }

        // 4. Price Filter (converted to paise in one place)
        $minPaise = $filters['min_price'] !== null && (float) $filters['min_price'] > 0
            ? (int) round((float) $filters['min_price'] * 100)
            : null;
        $maxPaise = $filters['max_price'] !== null && (float) $filters['max_price'] > 0
            ? (int) round((float) $filters['max_price'] * 100)
            : null;

        if ($minPaise !== null) {
            $query->where('min_price', '>=', $minPaise);
        }
        if ($maxPaise !== null) {
            $query->where('min_price', '<=', $maxPaise);
        }

        // 5. Discount Filter
        if ($filters['min_discount'] !== null) {
            $query->where('discount_percent', '>=', (int) $filters['min_discount']);
        }

        // 6. Dynamic Variant Attribute Filters
        $vertical = $this->resolveVertical($filters['category'], $filters['vertical']);
        if ($vertical && $filters['attrs'] !== []) {
            $sanitizedAttrs = $this->sanitizeAttributeFilters($filters['attrs'], $vertical);

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

        return $query;
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
     * Batch load review summaries for all visible products on the page to prevent N+1 queries.
     *
     * @return Collection<int, \App\Reviews\Models\ReviewSummary>
     */
    #[Computed]
    public function productSummaries(): Collection
    {
        if (! config('reviews.enabled', true)) {
            return collect();
        }

        $productIds = $this->products->pluck('id')->all();
        if (empty($productIds)) {
            return collect();
        }

        return app(\App\Reviews\Services\ReviewSummaryService::class)->getSummariesForProducts($productIds);
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

{{--
    Mobile sort and filter sheets (below lg). The filter sheet edits a local draft; only the live
    count is fetched while editing (previewFilters, no re-render) and Apply sends it in one request.
    Opening a sheet adds a history entry so the phone's back button closes it. That popstate is
    handled in the capture phase and stopped, so Livewire's navigate and #[Url] listeners never see
    it, and the action (apply/sort) runs only after the entry is popped, so its URL update lands on
    the page's own history entry.
--}}
<div
    class="mx-auto max-w-7xl px-4 pb-24 pt-6 sm:px-6 lg:px-8 lg:py-8"
    x-data="{
        sheet: null,
        pushed: false,
        pendingAction: null,
        group: 'category',
        draft: null,
        count: null,
        loading: false,
        attributeGroups: [],
        brandQuery: '',
        previewTimer: null,
        previewSeq: 0,
        emptyDraft() {
            return { category: '', vertical: '', brands: [], min_price: '', max_price: '', min_discount: '', in_stock: false, attrs: {} }
        },
        currentDraft() {
            let attrs = this.$wire.attrs
            return {
                category: this.$wire.category || '',
                vertical: this.$wire.vertical || '',
                brands: [...(this.$wire.brands || [])],
                min_price: this.$wire.min_price ?? '',
                max_price: this.$wire.max_price ?? '',
                min_discount: this.$wire.min_discount ?? '',
                in_stock: !! this.$wire.in_stock,
                attrs: Array.isArray(attrs) || ! attrs ? {} : JSON.parse(JSON.stringify(attrs)),
            }
        },
        get draftKey() {
            if (! this.draft) return ''
            let attrs = Object.fromEntries(Object.entries(this.draft.attrs).filter(([code, values]) => values.length))
            return JSON.stringify({ ...this.draft, attrs })
        },
        open(name) {
            if (name === 'filters') {
                this.draft = this.currentDraft()
                this.brandQuery = ''
                this.count = null
                this.preview(0)
            }
            this.sheet = name
            history.pushState({ productSheet: name }, '')
            this.pushed = true
        },
        close(action = null) {
            this.pendingAction = action
            this.pushed ? history.back() : this.closed()
        },
        closed() {
            let action = this.pendingAction
            this.pushed = false
            this.pendingAction = null
            this.sheet = null
            if (action) action()
        },
        onPopState(event) {
            if (! this.pushed) return
            event.stopImmediatePropagation()
            this.closed()
        },
        preview(delay = 300) {
            clearTimeout(this.previewTimer)
            this.previewTimer = setTimeout(() => {
                let seq = ++this.previewSeq
                this.loading = true
                this.$wire.previewFilters(this.draft).then((result) => {
                    if (seq !== this.previewSeq || ! this.draft) return
                    let codes = result.attributes.map((group) => group.code)
                    Object.keys(this.draft.attrs).forEach((code) => codes.includes(code) || delete this.draft.attrs[code])
                    codes.forEach((code) => Array.isArray(this.draft.attrs[code]) || (this.draft.attrs[code] = []))
                    this.attributeGroups = result.attributes
                    if (! ['category', 'price', 'discount', 'brand', 'availability'].includes(this.group) && ! codes.includes(this.group)) {
                        this.group = 'category'
                    }
                    this.count = result.count
                    this.loading = false
                })
            }, delay)
        },
        pickCategory(slug) {
            this.draft.category = this.draft.category === slug ? '' : slug
            this.draft.vertical = ''
            this.draft.attrs = {}
        },
        pickVertical(slug) {
            this.draft.vertical = this.draft.vertical === slug ? '' : slug
            this.draft.attrs = {}
        },
        isPrice(min, max) {
            return String(this.draft.min_price ?? '') === String(min ?? '') && String(this.draft.max_price ?? '') === String(max ?? '')
        },
        pickPrice(min, max) {
            let same = this.isPrice(min, max)
            this.draft.min_price = same ? '' : String(min ?? '')
            this.draft.max_price = same ? '' : String(max ?? '')
        },
        pickDiscount(value) {
            this.draft.min_discount = String(this.draft.min_discount ?? '') === String(value ?? '') ? '' : String(value ?? '')
        },
        groupCount(name) {
            let d = this.draft
            if (! d) return 0
            if (name === 'category') return (d.category ? 1 : 0) + (d.vertical ? 1 : 0)
            if (name === 'price') return (d.min_price !== '' && d.min_price !== null) || (d.max_price !== '' && d.max_price !== null) ? 1 : 0
            if (name === 'discount') return d.min_discount ? 1 : 0
            if (name === 'brand') return d.brands.length
            if (name === 'availability') return d.in_stock ? 1 : 0
            return (d.attrs[name] || []).length
        },
        apply() {
            let draft = JSON.parse(JSON.stringify(this.draft))
            this.close(() => this.$wire.applyFilters(draft))
        },
        sortBy(value) {
            this.close(() => this.$wire.set('sort', value))
        },
    }"
    x-init="$watch('draftKey', () => sheet === 'filters' && preview())"
    x-on:popstate.window.capture="onPopState($event)"
>
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
                @if ($this->searchResult)
                    Results for "{{ $this->searchResult->query }}"
                @elseif ($vertical)
                    {{ ucwords(str_replace('-', ' ', $vertical)) }}
                @elseif ($category)
                    {{ ucwords(str_replace('-', ' ', $category)) }}
                @else
                    All Products
                @endif
                <span class="ml-2 text-sm font-normal text-zinc-500 dark:text-zinc-400">({{ $this->products->total() }} items)</span>
            </h1>
            @if ($this->searchResult)
                <button
                    type="button"
                    wire:click="clearSearch"
                    class="mt-1 text-xs font-semibold text-amber-600 hover:text-amber-700 dark:text-amber-400 underline"
                >
                    Clear search
                </button>
            @endif
        </div>

        <!-- Sort Control (desktop; phones use the sort sheet) -->
        <div class="hidden items-center gap-3 lg:flex">
            <label for="sort-select" class="text-xs font-medium text-zinc-600 dark:text-zinc-400 whitespace-nowrap">Sort by:</label>
            <select
                id="sort-select"
                wire:model.live="sort"
                class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-sm font-medium text-zinc-800 shadow-xs focus:border-amber-500 focus:outline-hidden focus:ring-2 focus:ring-amber-500/20 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200"
            >
                @foreach ($this->sortOptions as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <!-- Active Filter Badges -->
    @php
        $hasActiveFilters = filled($this->category) || filled($this->vertical) || ! empty($this->brands) || filled($this->min_price) || filled($this->max_price) || filled($this->min_discount) || $this->in_stock || ! empty($this->attrs);
    @endphp

    @if ($hasActiveFilters)
        {{-- One swipeable row on phones, wrapping on desktop --}}
        <div class="-mx-4 mb-4 flex items-center gap-2 overflow-x-auto px-4 pb-1 sm:-mx-6 sm:px-6 lg:mx-0 lg:mb-6 lg:flex-wrap lg:overflow-visible lg:px-0 lg:pb-0 [&>*]:shrink-0 [&>*]:whitespace-nowrap">
            <span class="hidden text-xs font-medium text-zinc-500 lg:inline dark:text-zinc-400">Active filters:</span>

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
        <!-- Sidebar Filters (desktop; phones and tablets use the filter sheet) -->
        <aside class="hidden space-y-6 lg:block">
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
                    @foreach ($this->pricePresets as $preset)
                        <button
                            type="button"
                            wire:click="setPricePreset({{ $preset['min'] }}, {{ $preset['max'] ?? 'null' }})"
                            class="block w-full text-left px-2 py-1 rounded-md {{ $min_price === (string) $preset['min'] && $max_price === ($preset['max'] !== null ? (string) $preset['max'] : null) ? 'bg-amber-50 text-amber-700 font-medium dark:bg-amber-950 dark:text-amber-300' : 'text-zinc-600 hover:bg-zinc-100 dark:text-zinc-400 dark:hover:bg-zinc-800' }}"
                        >
                            {{ $preset['label'] }}
                        </button>
                    @endforeach
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
                    @foreach ($this->discountPresets as $disc)
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
                    @if ($this->searchResult && ! $hasActiveFilters)
                        <h3 class="text-base font-semibold text-zinc-900 dark:text-white">No results for "{{ $this->searchResult->query }}"</h3>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400 max-w-sm">
                            Check the spelling or try a more general word.
                        </p>
                        <button
                            type="button"
                            wire:click="clearSearch"
                            class="mt-4 inline-flex items-center rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white shadow-xs hover:bg-amber-600 transition"
                        >
                            Browse all products
                        </button>
                    @else
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
                    @endif
                </div>
            @else
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 sm:gap-6 lg:grid-cols-3 xl:grid-cols-4">
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
                                    <div class="absolute top-2 left-2 flex flex-col gap-1 items-start sm:top-2.5 sm:left-2.5">
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
                                <div class="flex flex-1 flex-col p-2.5 sm:p-4">
                                    <!-- Brand Name -->
                                    <p class="truncate text-[10px] font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400 mb-1 sm:text-[11px]">
                                        {{ $product->brand?->name ?? 'Brand' }}
                                    </p>

                                    <!-- Product Title -->
                                    <h2 class="text-[13px] font-medium text-zinc-900 dark:text-white line-clamp-2 leading-snug group-hover:text-amber-600 dark:group-hover:text-amber-400 transition mb-2 sm:text-sm">
                                        {{ $product->title }}
                                    </h2>

                                    <!-- Review Rating Badge -->
                                    @php $reviewSummary = $this->productSummaries->get($product->id); @endphp
                                    @if ($reviewSummary && $reviewSummary->rating_count > 0)
                                        <div class="mb-2">
                                            <x-product-rating-pill :summary="$reviewSummary" :show-link="false" />
                                        </div>
                                    @endif

                                    <!-- Sold By Merchant -->
                                    @if ($buyBox?->sellerProfile)
                                        <p class="hidden text-[11px] text-zinc-500 dark:text-zinc-400 mb-3 truncate sm:block">
                                            Sold by: <span class="font-medium text-zinc-700 dark:text-zinc-300">{{ $buyBox->sellerProfile->store_name }}</span>
                                        </p>
                                    @endif

                                    <!-- Price Container -->
                                    <div class="mt-auto flex flex-wrap items-baseline gap-x-2 pt-2 border-t border-zinc-100 dark:border-zinc-800">
                                        <span class="text-base font-bold text-zinc-900 dark:text-white sm:text-lg">
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

    <!-- Mobile Sort | Filter bar -->
    <div class="fixed inset-x-0 bottom-0 z-30 grid grid-cols-2 border-t border-zinc-200 bg-white/95 pb-[env(safe-area-inset-bottom)] backdrop-blur-md lg:hidden dark:border-zinc-800 dark:bg-zinc-900/95">
        <button
            type="button"
            x-on:click="open('sort')"
            aria-haspopup="dialog"
            class="flex items-center justify-center gap-2 py-3.5 text-sm font-semibold text-zinc-800 dark:text-zinc-100"
        >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5L7.5 3m0 0L12 7.5M7.5 3v13.5m13.5 0L16.5 21m0 0L12 16.5m4.5 4.5V7.5" />
            </svg>
            <span>Sort</span>
        </button>
        <button
            type="button"
            x-on:click="open('filters')"
            aria-haspopup="dialog"
            class="flex items-center justify-center gap-2 border-l border-zinc-200 py-3.5 text-sm font-semibold text-zinc-800 dark:border-zinc-800 dark:text-zinc-100"
        >
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3c2.755 0 5.455.232 8.083.678.533.09.917.556.917 1.096v1.044a2.25 2.25 0 01-.659 1.591l-5.432 5.432a2.25 2.25 0 00-.659 1.591v2.927a2.25 2.25 0 01-1.244 2.013L9.75 21v-6.568a2.25 2.25 0 00-.659-1.591L3.659 7.409A2.25 2.25 0 013 5.818V4.774c0-.54.384-1.006.917-1.096A48.32 48.32 0 0112 3z" />
            </svg>
            <span>Filter</span>
            @if ($this->activeFilterCount > 0)
                <span class="inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-amber-500 px-1.5 text-xs font-bold text-white">{{ $this->activeFilterCount }}</span>
            @endif
        </button>
    </div>

    <!-- Mobile Sort Sheet -->
    <div
        x-show="sheet === 'sort'"
        style="display: none"
        class="fixed inset-0 z-50 lg:hidden"
        role="dialog"
        aria-modal="true"
        aria-labelledby="sort-sheet-title"
    >
        <div class="absolute inset-0 bg-black/50" x-on:click="close()" x-show="sheet === 'sort'" x-transition.opacity></div>
        <div
            x-show="sheet === 'sort'"
            x-trap.noscroll="sheet === 'sort'"
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="translate-y-full"
            x-transition:enter-end="translate-y-0"
            x-transition:leave="transition ease-in duration-150"
            x-transition:leave-start="translate-y-0"
            x-transition:leave-end="translate-y-full"
            class="absolute inset-x-0 bottom-0 rounded-t-2xl bg-white pb-[env(safe-area-inset-bottom)] shadow-xl dark:bg-zinc-900"
        >
            <div class="flex items-center justify-between border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <h2 id="sort-sheet-title" class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">Sort by</h2>
                <button type="button" x-on:click="close()" class="rounded-lg p-1.5 text-zinc-500 hover:bg-zinc-100 dark:hover:bg-zinc-800" aria-label="Close sort">
                    <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
            <ul class="py-1">
                @foreach ($this->sortOptions as $value => $label)
                    <li>
                        <button
                            type="button"
                            x-on:click="sortBy(@js($value))"
                            class="flex w-full items-center justify-between px-4 py-3.5 text-left text-sm {{ $sort === $value ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-zinc-800 dark:text-zinc-200' }}"
                        >
                            <span>{{ $label }}</span>
                            <span class="flex h-5 w-5 items-center justify-center rounded-full border-2 {{ $sort === $value ? 'border-amber-500' : 'border-zinc-300 dark:border-zinc-600' }}">
                                @if ($sort === $value)
                                    <span class="h-2.5 w-2.5 rounded-full bg-amber-500"></span>
                                @endif
                            </span>
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>

    <!-- Mobile Filter Sheet -->
    <div
        x-show="sheet === 'filters'"
        x-trap.noscroll="sheet === 'filters'"
        x-transition.opacity.duration.150ms
        style="display: none"
        class="fixed inset-0 z-50 flex flex-col bg-white lg:hidden dark:bg-zinc-950"
        role="dialog"
        aria-modal="true"
        aria-labelledby="filter-sheet-title"
    >
        <template x-if="draft">
            <div class="flex h-full flex-col">
                <!-- Sheet header -->
                <div class="flex items-center justify-between border-b border-zinc-200 px-2 py-2 dark:border-zinc-800">
                    <div class="flex items-center gap-1">
                        <button type="button" x-on:click="close()" class="rounded-lg p-2 text-zinc-600 hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800" aria-label="Close filters">
                            <svg class="h-5 w-5" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18" />
                            </svg>
                        </button>
                        <h2 id="filter-sheet-title" class="text-base font-semibold text-zinc-900 dark:text-white">Filters</h2>
                    </div>
                    <button type="button" x-on:click="draft = emptyDraft()" class="px-3 py-2 text-sm font-semibold text-amber-600 dark:text-amber-400">
                        Clear all
                    </button>
                </div>

                <!-- Two panes: groups | options -->
                <div class="flex min-h-0 flex-1">
                    <nav class="w-32 shrink-0 overflow-y-auto bg-zinc-100 dark:bg-zinc-900" aria-label="Filter groups">
                        @php
                            $sheetGroups = ['category' => 'Category', 'price' => 'Price', 'discount' => 'Discount', 'brand' => 'Brand', 'availability' => 'Availability'];
                        @endphp
                        @foreach ($sheetGroups as $groupKey => $groupLabel)
                            <button
                                type="button"
                                x-on:click="group = @js($groupKey)"
                                x-bind:class="group === @js($groupKey) ? 'border-amber-500 bg-white font-semibold text-zinc-900 dark:bg-zinc-950 dark:text-white' : 'border-transparent text-zinc-600 dark:text-zinc-400'"
                                class="flex w-full items-center justify-between gap-1 border-l-4 px-3 py-3.5 text-left text-sm"
                            >
                                <span>{{ $groupLabel }}</span>
                                <span x-show="groupCount(@js($groupKey))" x-text="groupCount(@js($groupKey))" class="rounded-full bg-amber-500 px-1.5 text-[11px] font-bold text-white"></span>
                            </button>
                        @endforeach
                        <template x-for="attr in attributeGroups" :key="attr.code">
                            <button
                                type="button"
                                x-on:click="group = attr.code"
                                x-bind:class="group === attr.code ? 'border-amber-500 bg-white font-semibold text-zinc-900 dark:bg-zinc-950 dark:text-white' : 'border-transparent text-zinc-600 dark:text-zinc-400'"
                                class="flex w-full items-center justify-between gap-1 border-l-4 px-3 py-3.5 text-left text-sm"
                            >
                                <span x-text="attr.name"></span>
                                <span x-show="groupCount(attr.code)" x-text="groupCount(attr.code)" class="rounded-full bg-amber-500 px-1.5 text-[11px] font-bold text-white"></span>
                            </button>
                        </template>
                    </nav>

                    <div class="min-w-0 flex-1 overflow-y-auto px-4 py-3 text-sm">
                        <!-- Category -->
                        <div x-show="group === 'category'" class="space-y-3">
                            @foreach ($this->rootCategories as $cat)
                                <div>
                                    <button
                                        type="button"
                                        x-on:click="pickCategory(@js($cat->slug))"
                                        x-bind:class="draft.category === @js($cat->slug) && ! draft.vertical ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-900 dark:text-zinc-100'"
                                        class="w-full py-1.5 text-left font-semibold"
                                    >
                                        {{ $cat->name }}
                                    </button>
                                    <div class="mt-1 space-y-0.5 border-l border-zinc-200 pl-3 dark:border-zinc-800">
                                        @foreach ($cat->children->flatMap->verticals->concat($cat->verticals) as $v)
                                            <button
                                                type="button"
                                                x-on:click="pickVertical(@js($v->slug))"
                                                x-bind:class="draft.vertical === @js($v->slug) ? 'font-semibold text-amber-600 dark:text-amber-400' : 'text-zinc-600 dark:text-zinc-400'"
                                                class="block w-full py-1.5 text-left"
                                            >
                                                {{ $v->name }}
                                            </button>
                                        @endforeach
                                    </div>
                                </div>
                            @endforeach
                        </div>

                        <!-- Price -->
                        <div x-show="group === 'price'" class="space-y-1">
                            @foreach ($this->pricePresets as $preset)
                                <button
                                    type="button"
                                    x-on:click="pickPrice({{ $preset['min'] }}, {{ $preset['max'] ?? 'null' }})"
                                    x-bind:class="isPrice({{ $preset['min'] }}, {{ $preset['max'] ?? 'null' }}) ? 'bg-amber-50 font-semibold text-amber-700 dark:bg-amber-950 dark:text-amber-300' : 'text-zinc-700 dark:text-zinc-300'"
                                    class="block w-full rounded-lg px-3 py-2.5 text-left"
                                >
                                    {{ $preset['label'] }}
                                </button>
                            @endforeach
                            <div class="grid grid-cols-2 gap-2 pt-3">
                                <label class="text-xs text-zinc-500 dark:text-zinc-400">
                                    Min (₹)
                                    <input type="number" inputmode="numeric" min="0" placeholder="0" x-model.debounce.400ms="draft.min_price" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
                                </label>
                                <label class="text-xs text-zinc-500 dark:text-zinc-400">
                                    Max (₹)
                                    <input type="number" inputmode="numeric" min="0" placeholder="Any" x-model.debounce.400ms="draft.max_price" class="mt-1 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
                                </label>
                            </div>
                        </div>

                        <!-- Discount -->
                        <div x-show="group === 'discount'" class="space-y-1">
                            @foreach ($this->discountPresets as $disc)
                                <button
                                    type="button"
                                    x-on:click="pickDiscount({{ $disc }})"
                                    x-bind:class="String(draft.min_discount) === '{{ $disc }}' ? 'bg-amber-50 font-semibold text-amber-700 dark:bg-amber-950 dark:text-amber-300' : 'text-zinc-700 dark:text-zinc-300'"
                                    class="block w-full rounded-lg px-3 py-2.5 text-left"
                                >
                                    {{ $disc }}% or more
                                </button>
                            @endforeach
                        </div>

                        <!-- Brand -->
                        <div x-show="group === 'brand'">
                            @if ($this->availableBrands->count() > 8)
                                <input type="search" x-model="brandQuery" placeholder="Search brands" aria-label="Search brands" class="mb-2 w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-100" />
                            @endif
                            @foreach ($this->availableBrands as $brandModel)
                                <label
                                    x-show="! brandQuery || @js(mb_strtolower($brandModel->name)).includes(brandQuery.toLowerCase())"
                                    class="flex items-center gap-3 py-2.5 text-zinc-800 dark:text-zinc-200"
                                >
                                    <input type="checkbox" value="{{ $brandModel->slug }}" x-model="draft.brands" class="h-4 w-4 rounded border-zinc-300 text-amber-600 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800" />
                                    <span>{{ $brandModel->name }}</span>
                                </label>
                            @endforeach
                        </div>

                        <!-- Availability -->
                        <div x-show="group === 'availability'">
                            <label class="flex items-center gap-3 py-2.5 text-zinc-800 dark:text-zinc-200">
                                <input type="checkbox" x-model="draft.in_stock" class="h-4 w-4 rounded border-zinc-300 text-amber-600 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800" />
                                <span>Exclude out of stock items</span>
                            </label>
                        </div>

                        <!-- Variant attributes for the drafted vertical -->
                        <template x-for="attr in attributeGroups" :key="attr.code">
                            <div x-show="group === attr.code">
                                <template x-for="opt in attr.options" :key="opt.value">
                                    <label class="flex items-center gap-3 py-2.5 text-zinc-800 dark:text-zinc-200">
                                        <input type="checkbox" x-bind:value="opt.value" x-model="draft.attrs[attr.code]" class="h-4 w-4 rounded border-zinc-300 text-amber-600 focus:ring-amber-500 dark:border-zinc-700 dark:bg-zinc-800" />
                                        <span x-text="opt.label"></span>
                                    </label>
                                </template>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Sheet footer: live count + Apply -->
                <div class="flex items-center gap-3 border-t border-zinc-200 px-4 py-3 pb-[max(0.75rem,env(safe-area-inset-bottom))] dark:border-zinc-800">
                    <div class="min-w-0 flex-1">
                        <p class="text-base font-bold text-zinc-900 dark:text-white" x-text="count === null ? '…' : count.toLocaleString('en-IN')"></p>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400" x-text="count === 1 ? 'product found' : 'products found'"></p>
                    </div>
                    <button
                        type="button"
                        x-on:click="apply()"
                        x-bind:disabled="loading && count === null"
                        class="flex-1 rounded-lg bg-amber-500 px-4 py-3 text-sm font-semibold text-white shadow-xs hover:bg-amber-600 disabled:opacity-60"
                    >
                        Apply
                    </button>
                </div>
            </div>
        </template>
    </div>
</div>
