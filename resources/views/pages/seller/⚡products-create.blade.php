<?php

use App\Enums\AttributeType;
use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Enums\VariantQcStatus;
use App\Models\Brand;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\SellerProfile;
use App\Models\Vertical;
use App\Models\VerticalAttribute;
use App\Services\UpidGenerator;
use App\Services\VariantKeyNormalizer;
use App\Services\VariantOfferValidator;
use App\Services\VariantSyncService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.seller')] #[Title('Add Product')] class extends Component {
    use WithFileUploads;

    // Search catalog state
    public string $catalog_search = '';
    public bool $isCreatingNew = false;

    // Quick Sell Modal state (selling existing approved variant)
    public bool $showQuickSellModal = false;
    public ?int $selectedVariantId = null;
    public string $quick_sku = '';
    public string $quick_mrp = '';
    public string $quick_price = '';
    public string $quick_stock = '1';

    // Stepper Wizard state
    public int $currentStep = 1;
    public ?int $draftProductId = null;

    // Step 1: Vertical
    public ?int $vertical_id = null;
    public string $vertical_search = '';

    // Step 2: Brand
    public ?int $brand_id = null;
    public string $brand_search = '';
    public bool $showRequestBrandModal = false;
    public string $new_brand_name = '';
    public mixed $new_brand_logo = null;

    // Step 3: Details
    public string $title = '';
    public string $description = '';
    public array $highlights = [''];
    public array $specs = []; // Key-value map using attribute code and option value

    // Step 4: Variants & Images
    public array $selectedVariantOptions = []; // [code => [value1, value2]]
    public array $variantRows = []; // List of rows: [key, variant_attributes, sku, mrp, selling_price, stock, barcode, weight]
    public string $batch_mrp = '';
    public string $batch_price = '';
    public string $batch_stock = '';
    public array $uploadedImages = []; // Temporarily uploaded Livewire files
    public ?string $primaryImageKey = null;

    public function mount(): void
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        /** @var SellerProfile|null $seller */
        $seller = $user->sellerProfile;

        if (! $seller || $seller->status->value !== 'approved') {
            $this->redirect(route('seller.apply'), navigate: true);

            return;
        }

        // Set default Brand to "Generic" if exists
        $genericBrand = Brand::where('slug', 'generic')->where('status', BrandStatus::Approved)->first();
        if ($genericBrand) {
            $this->brand_id = $genericBrand->id;
        }

        // Check if there is an in-progress draft for this seller
        $existingDraft = Product::where('created_by', $seller->id)
            ->where('qc_status', ProductQcStatus::Draft)
            ->latest('id')
            ->first();

        if ($existingDraft) {
            $this->loadDraft($existingDraft);
        }
    }

    public function loadDraft(Product $draft): void
    {
        $this->draftProductId = $draft->id;
        $this->vertical_id = $draft->vertical_id;
        $this->brand_id = $draft->brand_id;
        $this->title = $draft->title;
        $this->description = $draft->description ?? '';
        $this->highlights = ! empty($draft->highlights) ? $draft->highlights : [''];
        $this->specs = $draft->specs ?? [];

        // Load variants if existing
        $variants = $draft->variants()->with('listings')->get();
        if ($variants->isNotEmpty()) {
            $this->variantRows = [];
            $this->selectedVariantOptions = [];
            foreach ($variants as $v) {
                if (is_array($v->variant_attributes)) {
                    foreach ($v->variant_attributes as $attrCode => $val) {
                        if (! isset($this->selectedVariantOptions[$attrCode])) {
                            $this->selectedVariantOptions[$attrCode] = [];
                        }
                        if (is_string($val) && ! in_array($val, $this->selectedVariantOptions[$attrCode], true)) {
                            $this->selectedVariantOptions[$attrCode][] = $val;
                        }
                    }
                }

                $listing = $v->listings->where('seller_profile_id', Auth::user()->sellerProfile->id)->first();
                $this->variantRows[] = [
                    'id' => $v->id,
                    'key' => $v->variant_key,
                    'variant_attributes' => $v->variant_attributes,
                    'sku' => $listing ? $listing->sku : '',
                    'mrp' => $listing ? (string) $listing->mrpInRupees() : '',
                    'selling_price' => $listing ? (string) $listing->sellingPriceInRupees() : '',
                    'stock' => $listing ? (string) $listing->stock : '0',
                    'barcode' => $v->barcode ?? '',
                    'weight' => (string) ($v->weight ?? ''),
                ];
            }
        }

        $this->isCreatingNew = true;
    }

    public function startNewProduct(): void
    {
        $this->reset([
            'draftProductId', 'vertical_id', 'title', 'description',
            'highlights', 'specs', 'selectedVariantOptions', 'variantRows',
        ]);
        $this->highlights = [''];
        $this->currentStep = 1;
        $this->isCreatingNew = true;

        $genericBrand = Brand::where('slug', 'generic')->first();
        if ($genericBrand) {
            $this->brand_id = $genericBrand->id;
        }
    }

    // --- Search Existing Catalog & Quick Sell ---
    public function getSearchResultsProperty()
    {
        if (strlen(trim($this->catalog_search)) < 2) {
            return collect();
        }

        $term = trim($this->catalog_search);

        return ProductVariant::with(['product.brand', 'product.vertical.category', 'images'])
            ->where('qc_status', VariantQcStatus::Approved)
            ->where(function ($q) use ($term) {
                $q->where('upid', 'like', "%{$term}%")
                    ->orWhere('barcode', $term)
                    ->orWhereHas('product', function ($sub) use ($term) {
                        $sub->where('qc_status', ProductQcStatus::Approved)
                            ->where('title', 'like', "%{$term}%");
                    });
            })
            ->take(8)
            ->get();
    }

    public function openQuickSellModal(int $variantId): void
    {
        $variant = ProductVariant::with(['product.brand'])->findOrFail($variantId);
        $this->selectedVariantId = $variant->id;

        /** @var SellerProfile $seller */
        $seller = Auth::user()->sellerProfile;

        // Auto-suggest SKU
        $brandSlug = $variant->product->brand ? $variant->product->brand->slug : 'brand';
        $titleSlug = Str::slug($variant->product->title);
        $this->quick_sku = strtoupper("{$brandSlug}-{$titleSlug}-{$variant->id}");
        $this->quick_mrp = '';
        $this->quick_price = '';
        $this->quick_stock = '10';
        $this->showQuickSellModal = true;
    }

    public function submitQuickSell(): void
    {
        $this->validate([
            'quick_sku' => ['required', 'string', 'max:100'],
            'quick_mrp' => ['required', 'numeric', 'min:1'],
            'quick_price' => ['required', 'numeric', 'min:1', 'lte:quick_mrp'],
            'quick_stock' => ['required', 'integer', 'min:0'],
        ]);

        /** @var SellerProfile $seller */
        $seller = Auth::user()->sellerProfile;
        $variant = ProductVariant::with('product')->findOrFail($this->selectedVariantId);

        $mrpPaise = (int) round(((float) $this->quick_mrp) * 100);
        $sellingPricePaise = (int) round(((float) $this->quick_price) * 100);

        // Uses createOrRestore to handle archived listings without duplicate key error
        Listing::createOrRestore([
            'seller_profile_id' => $seller->id,
            'product_variant_id' => $variant->id,
            'sku' => $this->quick_sku,
            'mrp' => $mrpPaise,
            'selling_price' => $sellingPricePaise,
            'stock' => (int) $this->quick_stock,
            'status' => ListingStatus::Active,
        ]);

        $this->showQuickSellModal = false;
        session()->flash('status', "Product [{$variant->product->title}] listed successfully. Your offer is now live!");
        $this->redirect(route('seller.dashboard'), navigate: true);
    }

    // --- Stepper Navigation & Autosave ---

    public function selectVertical(int $verticalId): void
    {
        $this->vertical_id = $verticalId;
        $this->autosaveDraft();
        $this->currentStep = 2;
    }

    public function selectBrand(int $brandId): void
    {
        $this->brand_id = $brandId;
        $this->autosaveDraft();
        $this->currentStep = 3;
    }

    public function requestNewBrand(): void
    {
        $this->validate([
            'new_brand_name' => ['required', 'string', 'min:2', 'max:100', 'unique:brands,name'],
            'new_brand_logo' => ['nullable', 'image', 'max:2048'],
        ]);

        /** @var SellerProfile $seller */
        $seller = Auth::user()->sellerProfile;

        $logoPath = null;
        if ($this->new_brand_logo) {
            $logoPath = $this->new_brand_logo->store('brand-logos', 'public');
        }

        $brand = Brand::create([
            'name' => $this->new_brand_name,
            'logo_path' => $logoPath,
            'status' => BrandStatus::Pending,
            'requested_by' => $seller->id,
        ]);

        $this->brand_id = $brand->id;
        $this->showRequestBrandModal = false;
        $this->reset(['new_brand_name', 'new_brand_logo']);

        $this->autosaveDraft();
        session()->flash('brand_requested', "Brand [{$brand->name}] submitted for review and selected for this product.");
        $this->currentStep = 3;
    }

    public function addHighlight(): void
    {
        if (count($this->highlights) < 8) {
            $this->highlights[] = '';
        }
    }

    public function removeHighlight(int $index): void
    {
        unset($this->highlights[$index]);
        $this->highlights = array_values($this->highlights);
        if (empty($this->highlights)) {
            $this->highlights = [''];
        }
    }

    public function saveStep3(): void
    {
        $this->validate([
            'title' => ['required', 'string', 'min:5', 'max:255'],
            'description' => ['required', 'string', 'min:10', 'max:3000'],
        ]);

        $this->highlights = array_values(array_filter($this->highlights, fn ($h) => trim($h) !== ''));
        if (empty($this->highlights)) {
            $this->highlights = [''];
        }

        $this->autosaveDraft();
        $this->currentStep = 4;
    }

    public function isVariantOptionSelected(string $code, string $value): bool
    {
        $options = $this->selectedVariantOptions[$code] ?? null;

        return is_array($options) && in_array($value, $options, true);
    }

    public function toggleVariantOption(string $code, string $value): void
    {
        if (! isset($this->selectedVariantOptions[$code]) || ! is_array($this->selectedVariantOptions[$code])) {
            $this->selectedVariantOptions[$code] = [];
        }

        if (in_array($value, $this->selectedVariantOptions[$code], true)) {
            $this->selectedVariantOptions[$code] = array_values(array_diff($this->selectedVariantOptions[$code], [$value]));
        } else {
            // Guard: Cannot add hidden/retired options to a new variant unless already used on this product
            $draftProd = $this->draftProductId ? Product::with('variants')->find($this->draftProductId) : null;
            $varAttr = VerticalAttribute::where('vertical_id', $this->vertical_id)->where('code', $code)->first();
            if ($varAttr) {
                $opt = collect($varAttr->options ?? [])->firstWhere('value', $value);
                $isActive = ! empty($opt['is_active']) && empty($opt['is_retired']);

                $isExistingOnProduct = false;
                if ($draftProd) {
                    foreach ($draftProd->variants as $v) {
                        if (! $v->trashed() && isset($v->variant_attributes[$code]) && (string) $v->variant_attributes[$code] === (string) $value) {
                            $isExistingOnProduct = true;
                            break;
                        }
                    }
                }

                if (! $isActive && ! $isExistingOnProduct) {
                    $this->addError('variantRows', "Option '{$value}' is retired or inactive and cannot be added to a new variant.");

                    return;
                }
            }

            $this->selectedVariantOptions[$code][] = $value;
        }

        $this->generateVariantGrid();
    }

    public function removeVariantRow(int $index): void
    {
        unset($this->variantRows[$index]);
        $this->variantRows = array_values($this->variantRows);
    }

    public function generateVariantGrid(): void
    {
        $cleanSelected = [];
        foreach ($this->selectedVariantOptions as $code => $values) {
            if (is_array($values) && ! empty($values)) {
                $cleanSelected[$code] = array_values(array_filter($values, fn ($v) => is_string($v) && $v !== ''));
            }
        }

        if (empty($cleanSelected)) {
            $this->variantRows = [];

            return;
        }

        // Build Cartesian product
        $arrays = $cleanSelected;

        $combinations = [[]];
        foreach ($arrays as $key => $values) {
            $tmp = [];
            foreach ($combinations as $combination) {
                foreach ($values as $value) {
                    $tmp[] = array_merge($combination, [$key => $value]);
                }
            }
            $combinations = $tmp;
        }

        $newRows = [];
        $brand = Brand::find($this->brand_id);
        $brandSlug = $brand ? $brand->slug : 'brand';
        $titleSlug = Str::slug($this->title ?: 'prod');

        foreach ($combinations as $combo) {
            $normKey = VariantKeyNormalizer::normalize($combo);

            // Check if this row already existed
            $existing = collect($this->variantRows)->firstWhere('key', $normKey);

            if ($existing) {
                $newRows[] = $existing;
            } else {
                // If draft already has this variant in DB, retain its id and existing offer values
                $existingDbVariant = null;
                $existingListing = null;
                if ($this->draftProductId) {
                    $existingDbVariant = ProductVariant::where('product_id', $this->draftProductId)
                        ->where('variant_key', $normKey)
                        ->with('listings')
                        ->first();
                    if ($existingDbVariant && Auth::user()?->sellerProfile) {
                        $existingListing = $existingDbVariant->listings->firstWhere('seller_profile_id', Auth::user()->sellerProfile->id);
                    }
                }

                $newRows[] = [
                    'id' => $existingDbVariant?->id,
                    'variant_id' => $existingDbVariant?->id,
                    'key' => $normKey,
                    'variant_attributes' => $combo,
                    'sku' => $existingListing?->sku ?? '',
                    'mrp' => $existingListing ? (string) $existingListing->mrpInRupees() : ($this->batch_mrp ?: ''),
                    'selling_price' => $existingListing ? (string) $existingListing->sellingPriceInRupees() : ($this->batch_price ?: ''),
                    'stock' => $existingListing ? (string) $existingListing->stock : ($this->batch_stock ?: ''),
                    'barcode' => $existingDbVariant?->barcode ?? '',
                    'weight' => (string) ($existingDbVariant?->weight ?? ''),
                ];
            }
        }

        $this->variantRows = $newRows;
    }

    public function applyToAll(): void
    {
        if ($this->batch_mrp !== '') {
            foreach ($this->variantRows as &$row) {
                $row['mrp'] = $this->batch_mrp;
            }
        }
        if ($this->batch_price !== '') {
            foreach ($this->variantRows as &$row) {
                $row['selling_price'] = $this->batch_price;
            }
        }
        if ($this->batch_stock !== '') {
            foreach ($this->variantRows as &$row) {
                $row['stock'] = $this->batch_stock;
            }
        }
    }

    public function saveStep4(): void
    {
        $this->autosaveDraft();

        if (! $this->draftProductId) {
            $this->addError('variantRows', 'Please select a vertical and brand first.');

            return;
        }

        if (empty($this->variantRows)) {
            $this->addError('variantRows', 'Please select at least one variant combination.');

            return;
        }

        $draft = Product::find($this->draftProductId);
        /** @var SellerProfile $seller */
        $seller = Auth::user()->sellerProfile;

        $validator = new VariantOfferValidator();
        $validationResult = $validator->validateRows($this->variantRows, $seller->id, $draft, requireAtLeastOneActive: true);

        if (! empty($validationResult['errors'])) {
            foreach ($validationResult['errors'] as $key => $msg) {
                if ($key === 'general') {
                    $this->addError('variantRows', $msg);
                } else {
                    $this->addError("variantRows.{$key}", $msg);
                }
            }

            return;
        }

        // Sync valid rows to DB via transaction & lock
        $syncService = new VariantSyncService();
        $syncService->sync($draft, $seller, $validationResult['validRows']);

        // Update variantRows with fresh DB variant IDs
        $freshVariants = $draft->variants()->get();
        foreach ($this->variantRows as &$r) {
            $matched = $freshVariants->firstWhere('variant_key', $r['key'] ?? '');
            if ($matched) {
                $r['id'] = $matched->id;
                $r['variant_id'] = $matched->id;
            }
        }
        unset($r);

        $this->currentStep = 5;
    }

    public function autosaveDraft(): void
    {
        /** @var SellerProfile $seller */
        $seller = Auth::user()->sellerProfile;

        if (! $this->vertical_id || ! $this->brand_id) {
            return;
        }

        $productData = [
            'vertical_id' => $this->vertical_id,
            'brand_id' => $this->brand_id,
            'title' => $this->title ?: 'Untitled Draft Product',
            'description' => $this->description ?: '',
            'highlights' => array_values(array_filter($this->highlights, fn ($h) => trim($h) !== '')),
            'specs' => $this->specs,
            'created_by' => $seller->id,
            'qc_status' => ProductQcStatus::Draft,
        ];

        if ($this->draftProductId) {
            $draft = Product::find($this->draftProductId);
            if ($draft) {
                $draft->update($productData);
            }
        } else {
            $draft = Product::create($productData);
            $this->draftProductId = $draft->id;
        }

        // Save uploaded images
        if (! empty($this->uploadedImages) && $this->draftProductId) {
            $draft = Product::find($this->draftProductId);
            foreach ($this->uploadedImages as $idx => $img) {
                $path = $img->store('product-images', 'public');
                ProductImage::create([
                    'product_id' => $draft->id,
                    'path' => $path,
                    'sort_order' => $idx,
                    'is_primary' => $idx === 0,
                    'status' => ProductImageStatus::Live,
                ]);
            }
            $this->uploadedImages = [];
        }
    }

    public function submitToQc(): void
    {
        $this->autosaveDraft();

        $draft = Product::with(['variants.listings', 'images', 'vertical.attributes'])->findOrFail($this->draftProductId);

        // Check for variant issues or needs_update
        $issues = $draft->variantIssues();
        if (! empty($issues) || $draft->needs_update) {
            $issueSummary = ! empty($issues) ? collect($issues)->pluck('message')->join('; ') : 'Product requires variant updates before QC submission.';
            session()->flash('qc_error', "Cannot submit for QC: {$issueSummary}");
            $this->currentStep = 4;

            return;
        }

        // Validate complete product ready for QC
        if (strlen($draft->title) < 5 || strlen($draft->description) < 10) {
            session()->flash('qc_error', 'Title and description must be completed before submitting.');
            $this->currentStep = 3;

            return;
        }

        if ($draft->variants->isEmpty()) {
            session()->flash('qc_error', 'At least one variant must be created.');
            $this->currentStep = 4;

            return;
        }

        if ($draft->images->isEmpty()) {
            session()->flash('qc_error', 'At least one product image must be uploaded before submitting to QC.');
            $this->currentStep = 4;

            return;
        }

        // Transition product and variants to QC Pending
        $draft->submitForQc();
        foreach ($draft->variants as $variant) {
            $variant->submitForQc();
        }

        session()->flash('status', "Product [{$draft->title}] submitted for QC review. You will be notified once approved.");
        $this->redirect(route('seller.dashboard'), navigate: true);
    }

    public function getSelectedVerticalModelProperty(): ?Vertical
    {
        return $this->vertical_id ? Vertical::with(['category', 'attributes'])->find($this->vertical_id) : null;
    }

    public function getSelectedBrandModelProperty(): ?Brand
    {
        return $this->brand_id ? Brand::find($this->brand_id) : null;
    }

    public function getAvailableVerticalsProperty()
    {
        return Vertical::with('category')
            ->where('is_active', true)
            ->when($this->vertical_search !== '', function ($q) {
                $q->where('name', 'like', "%{$this->vertical_search}%")
                    ->orWhereHas('category', fn ($c) => $c->where('name', 'like', "%{$this->vertical_search}%"));
            })
            ->orderBy('name')
            ->get();
    }

    public function getAvailableBrandsProperty()
    {
        return Brand::where('status', BrandStatus::Approved)
            ->orWhere(fn ($q) => $q->where('requested_by', Auth::user()->sellerProfile?->id))
            ->when($this->brand_search !== '', fn ($q) => $q->where('name', 'like', "%{$this->brand_search}%"))
            ->orderBy('name')
            ->get();
    }

    public function getFeePreview(float|int|string $price, ?int $verticalId = null, ?float $weightKg = null): ?\App\Fees\DTOs\FeeBreakdownDto
    {
        $priceFloat = (float) $price;
        if ($priceFloat <= 0) {
            return null;
        }

        $calculator = app(\App\Fees\Contracts\FeeCalculatorInterface::class);
        $profile = Auth::user()?->sellerProfile;

        $weightGrams = $weightKg !== null ? (int) round($weightKg * 1000) : null;

        $input = new \App\Fees\DTOs\FeeQuoteInput(
            pricePaise: (int) round($priceFloat * 100),
            productGstRate: 12.0,
            verticalId: $verticalId,
            weightGrams: $weightGrams,
            sellerPickupState: $profile?->pickup_state,
            sellerPickupPincode: $profile?->pickup_postal_code,
            deliveryState: null,
            deliveryPincode: null,
            paymentMethod: 'cod',
            sellerHasPan: ! empty($profile?->pan),
            effectiveDate: now(),
            qty: 1,
        );

        try {
            return $calculator->quote($input);
        } catch (\Throwable $e) {
            return null;
        }
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Add New Product</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Search existing catalog to sell in seconds, or create a brand-new catalog product with variants.
            </p>
        </div>

        @if ($isCreatingNew)
            <button wire:click="$set('isCreatingNew', false)" type="button"
                    class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 transition">
                ← Back to Catalog Search
            </button>
        @endif
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-900/50 dark:bg-green-950/30 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    @if (session('qc_error'))
        <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
            {{ session('qc_error') }}
        </div>
    @endif

    <!-- SECTION A: ONE UNIFIED CATALOG SEARCH BOX -->
    @if (! $isCreatingNew)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-6">
            <div>
                <label for="catalog-search" class="block text-base font-bold text-zinc-900 dark:text-white">
                    Step 1: Check if this product already exists in the Catalog
                </label>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                    Entering a UPID (e.g. <span class="font-mono">KHP...</span>), barcode, or title allows you to list immediately with zero wait time and no QC required.
                </p>

                <div class="relative mt-3">
                    <input wire:model.live.debounce.300ms="catalog_search" type="text" id="catalog-search"
                           placeholder="Search catalog by UPID, barcode, or product name..."
                           class="block w-full rounded-lg border border-zinc-300 bg-white py-3 pl-10 pr-4 text-sm text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                    <svg class="pointer-events-none absolute left-3.5 top-3.5 size-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>
            </div>

            <!-- Search Results Display -->
            @if ($this->searchResults->isNotEmpty())
                <div class="space-y-3 pt-2">
                    <h4 class="text-xs font-semibold uppercase tracking-wider text-zinc-500">Catalog Matches Found</h4>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        @foreach ($this->searchResults as $match)
                            <div wire:key="match-{{ $match->id }}" class="flex items-center justify-between rounded-xl border border-zinc-200 p-4 hover:border-zinc-300 dark:border-zinc-800 dark:hover:border-zinc-700 transition">
                                <div class="flex items-center gap-3">
                                    @php $img = $match->images->first() ?? $match->product->images->first(); @endphp
                                    @if ($img && $img->url())
                                        <img src="{{ $img->url() }}" alt="Product" class="size-12 rounded-lg object-cover border border-zinc-200 dark:border-zinc-700" />
                                    @else
                                        <div class="flex size-12 items-center justify-center rounded-lg bg-zinc-100 dark:bg-zinc-800 text-xs text-zinc-400 font-bold">
                                            KHP
                                        </div>
                                    @endif
                                    <div>
                                        <h5 class="text-sm font-semibold text-zinc-900 dark:text-white">{{ $match->product->title }}</h5>
                                        <div class="text-xs text-zinc-500">
                                            Brand: <strong>{{ $match->product->brand?->name }}</strong> ·
                                            <span class="font-mono text-zinc-400">{{ $match->upid }}</span>
                                        </div>
                                        <div class="mt-1 flex flex-wrap gap-1">
                                            @foreach ($match->variant_attributes as $k => $v)
                                                <span class="inline-flex rounded-sm bg-zinc-100 px-1.5 py-0.2 text-[10px] font-medium text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                                    {{ $k }}: {{ $v }}
                                                </span>
                                            @endforeach
                                        </div>
                                    </div>
                                </div>

                                <button wire:click="openQuickSellModal({{ $match->id }})" type="button" data-test="sell-this-product-btn"
                                        class="rounded-lg bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 transition shadow-xs">
                                    Sell This
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @elseif (strlen(trim($catalog_search)) >= 2)
                <div class="rounded-xl border border-dashed border-zinc-200 p-6 text-center dark:border-zinc-800">
                    <p class="text-xs text-zinc-500">No approved catalog products match "{{ $catalog_search }}".</p>
                    <button wire:click="startNewProduct" type="button" data-test="create-new-product-btn"
                            class="mt-3 inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                        + Create New Catalog Product
                    </button>
                </div>
            @endif

            <!-- Not found CTA -->
            <div class="flex items-center justify-between border-t border-zinc-100 pt-6 dark:border-zinc-800">
                <span class="text-xs text-zinc-500">Product is not yet in the catalog?</span>
                <button wire:click="startNewProduct" type="button" data-test="start-new-stepper-btn"
                        class="rounded-lg border border-zinc-300 bg-white px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 dark:hover:bg-zinc-700 transition">
                    Create New Product From Scratch →
                </button>
            </div>
        </div>
    @endif

    <!-- SECTION B: 5-STEP RESUMABLE CREATION WIZARD -->
    @if ($isCreatingNew)
        <div class="space-y-6">
            <!-- Stepper Progress Bar -->
            <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <nav class="flex items-center justify-between text-xs font-medium">
                    <button wire:click="$set('currentStep', 1)" type="button"
                            class="flex items-center gap-2 {{ $currentStep >= 1 ? 'text-zinc-900 dark:text-white font-bold' : 'text-zinc-400' }}">
                        <span class="flex size-6 items-center justify-center rounded-full {{ $currentStep >= 1 ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-400' }}">1</span>
                        <span>Vertical</span>
                    </button>
                    <span class="text-zinc-300 dark:text-zinc-700">──</span>

                    <button wire:click="$set('currentStep', 2)" type="button"
                            class="flex items-center gap-2 {{ $currentStep >= 2 ? 'text-zinc-900 dark:text-white font-bold' : 'text-zinc-400' }}">
                        <span class="flex size-6 items-center justify-center rounded-full {{ $currentStep >= 2 ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-400' }}">2</span>
                        <span>Brand</span>
                    </button>
                    <span class="text-zinc-300 dark:text-zinc-700">──</span>

                    <button wire:click="$set('currentStep', 3)" type="button"
                            class="flex items-center gap-2 {{ $currentStep >= 3 ? 'text-zinc-900 dark:text-white font-bold' : 'text-zinc-400' }}">
                        <span class="flex size-6 items-center justify-center rounded-full {{ $currentStep >= 3 ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-400' }}">3</span>
                        <span>Details & Specs</span>
                    </button>
                    <span class="text-zinc-300 dark:text-zinc-700">──</span>

                    <button wire:click="$set('currentStep', 4)" type="button"
                            class="flex items-center gap-2 {{ $currentStep >= 4 ? 'text-zinc-900 dark:text-white font-bold' : 'text-zinc-400' }}">
                        <span class="flex size-6 items-center justify-center rounded-full {{ $currentStep >= 4 ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-400' }}">4</span>
                        <span>Variants & Images</span>
                    </button>
                    <span class="text-zinc-300 dark:text-zinc-700">──</span>

                    <button wire:click="$set('currentStep', 5)" type="button"
                            class="flex items-center gap-2 {{ $currentStep >= 5 ? 'text-zinc-900 dark:text-white font-bold' : 'text-zinc-400' }}">
                        <span class="flex size-6 items-center justify-center rounded-full {{ $currentStep >= 5 ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : 'bg-zinc-100 dark:bg-zinc-800 text-zinc-400' }}">5</span>
                        <span>Review & Submit</span>
                    </button>
                </nav>
            </div>

            <!-- STEP 1: VERTICAL PICKER -->
            @if ($currentStep === 1)
                <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
                    <div>
                        <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Step 1: Select Product Vertical</h3>
                        <p class="text-xs text-zinc-500">Choose the specific product category to load the correct tax rates and spec attributes.</p>
                    </div>

                    <div class="relative max-w-sm">
                        <input wire:model.live.debounce.200ms="vertical_search" type="text" placeholder="Search verticals (e.g. T-Shirt, Mobile)..."
                               class="block w-full rounded-lg border border-zinc-300 bg-white py-2 pl-9 pr-3 text-xs text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        <svg class="pointer-events-none absolute left-3 top-2.5 size-3.5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>

                    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3 pt-2">
                        @forelse ($this->availableVerticals as $v)
                            <button wire:click="selectVertical({{ $v->id }})" type="button"
                                    class="flex flex-col text-left rounded-xl border p-4 transition {{ $vertical_id === $v->id ? 'border-zinc-900 bg-zinc-50 ring-2 ring-zinc-900 dark:border-white dark:bg-zinc-800/80 dark:ring-white' : 'border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700' }}">
                                <span class="font-semibold text-sm text-zinc-900 dark:text-white">{{ $v->name }}</span>
                                <span class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $v->breadcrumbString() }}</span>
                                <span class="mt-2 text-[11px] font-mono text-zinc-400">HSN: {{ $v->hsn_code }} (GST: {{ $v->gst_rate }}%)</span>
                            </button>
                        @empty
                            <div class="col-span-full py-8 text-center text-xs text-zinc-400">
                                No verticals found matching your search.
                            </div>
                        @endforelse
                    </div>
                </div>
            @endif

            <!-- STEP 2: BRAND -->
            @if ($currentStep === 2)
                <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Step 2: Select Brand</h3>
                            <p class="text-xs text-zinc-500">Select an approved brand. 'Generic' is preselected as default.</p>
                        </div>
                        <button wire:click="$set('showRequestBrandModal', true)" type="button"
                                class="rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 transition">
                            + Request New Brand
                        </button>
                    </div>

                    @if (session('brand_requested'))
                        <div class="rounded-lg border border-green-200 bg-green-50 p-3 text-xs text-green-800 dark:border-green-900/50 dark:bg-green-950/30 dark:text-green-300">
                            {{ session('brand_requested') }}
                        </div>
                    @endif

                    <div class="relative max-w-sm">
                        <input wire:model.live.debounce.200ms="brand_search" type="text" placeholder="Search brands..."
                               class="block w-full rounded-lg border border-zinc-300 bg-white py-2 pl-9 pr-3 text-xs text-zinc-900 placeholder:text-zinc-400 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        <svg class="pointer-events-none absolute left-3 top-2.5 size-3.5 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                        </svg>
                    </div>

                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4 pt-2">
                        @foreach ($this->availableBrands as $b)
                            <button wire:click="selectBrand({{ $b->id }})" type="button"
                                    class="flex items-center gap-3 rounded-xl border p-3 transition {{ $brand_id === $b->id ? 'border-zinc-900 bg-zinc-50 ring-2 ring-zinc-900 dark:border-white dark:bg-zinc-800/80 dark:ring-white' : 'border-zinc-200 bg-white hover:border-zinc-300 dark:border-zinc-800 dark:bg-zinc-900 dark:hover:border-zinc-700' }}">
                                @if ($b->logoUrl())
                                    <img src="{{ $b->logoUrl() }}" alt="{{ $b->name }}" class="size-7 rounded-md object-contain border border-zinc-200 dark:border-zinc-700" />
                                @else
                                    <span class="flex size-7 items-center justify-center rounded-md bg-zinc-100 font-bold text-xs text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300">
                                        {{ strtoupper(substr($b->name, 0, 2)) }}
                                    </span>
                                @endif
                                <div class="text-left">
                                    <div class="font-semibold text-xs text-zinc-900 dark:text-white">{{ $b->name }}</div>
                                    @if ($b->status === BrandStatus::Pending)
                                        <div class="text-[10px] text-amber-500 font-medium">Pending Review</div>
                                    @endif
                                </div>
                            </button>
                        @endforeach
                    </div>

                    <div class="flex justify-between pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="button" wire:click="$set('currentStep', 1)" class="rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                            ← Back to Vertical
                        </button>
                    </div>
                </div>
            @endif

            <!-- STEP 3: DETAILS & DYNAMIC SPECS -->
            @if ($currentStep === 3)
                <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-6">
                    <div>
                        <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Step 3: Product Details & Specifications</h3>
                        <p class="text-xs text-zinc-500">
                            Enter the family details for <strong>{{ $this->selectedVerticalModel?->name }}</strong> under brand <strong>{{ $this->selectedBrandModel?->name }}</strong>.
                        </p>
                    </div>

                    <form wire:submit="saveStep3" class="space-y-5">
                        <div>
                            <label for="prod-title" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Product Title *</label>
                            <input wire:model="title" type="text" id="prod-title" required placeholder="e.g. Men Slim Fit Round Neck Cotton T-Shirt"
                                   class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            <p class="mt-1 text-xs text-zinc-400">Helpful format: [Brand] + [Target Gender] + [Key Style] + [Color/Material] + [Product Type]</p>
                            @error('title') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="prod-desc" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Description *</label>
                            <textarea wire:model="description" id="prod-desc" rows="4" required placeholder="Describe your product materials, fit, care instructions, and warranty..."
                                      class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"></textarea>
                            @error('description') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <!-- Highlights Bullet Points -->
                        <div>
                            <div class="flex items-center justify-between">
                                <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Key Highlights (Bullet Points)</label>
                                <button type="button" wire:click="addHighlight" class="text-xs font-semibold text-emerald-600 hover:underline dark:text-emerald-400">
                                    + Add Point
                                </button>
                            </div>
                            <div class="mt-2 space-y-2">
                                @foreach ($highlights as $hIndex => $highlight)
                                    <div wire:key="hl-{{ $hIndex }}" class="flex items-center gap-2">
                                        <input wire:model="highlights.{{ $hIndex }}" type="text" placeholder="e.g. 100% Breathable Combed Cotton Fabric"
                                               class="block w-full rounded-lg border border-zinc-300 bg-white px-3 py-1.5 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                        @if (count($highlights) > 1)
                                            <button type="button" wire:click="removeHighlight({{ $hIndex }})" class="text-red-500 hover:text-red-700 p-1">
                                                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                                                </svg>
                                            </button>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        </div>

                        <!-- Dynamic Non-Variant Specifications Generated From Vertical -->
                        @if ($this->selectedVerticalModel)
                            <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800 space-y-4">
                                <h4 class="text-sm font-bold text-zinc-900 dark:text-white">Specifications ({{ $this->selectedVerticalModel->name }})</h4>
                                <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                                    @foreach ($this->selectedVerticalModel->attributes()->where('is_variant', false)->orderBy('sort_order')->get() as $specAttr)
                                        <div>
                                            <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">
                                                {{ $specAttr->name }}
                                                @if ($specAttr->is_required) <span class="text-red-500">*</span> @endif
                                                @if ($specAttr->unit) <span class="text-zinc-400">({{ $specAttr->unit }})</span> @endif
                                            </label>

                                            @if ($specAttr->type === AttributeType::Select)
                                                <select wire:model="specs.{{ $specAttr->code }}" {{ $specAttr->is_required ? 'required' : '' }}
                                                        class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                                                    <option value="">— Select {{ $specAttr->name }} —</option>
                                                    @foreach ($specAttr->activeOptions() as $opt)
                                                        <option value="{{ $opt['value'] }}">{{ $opt['label'] }}</option>
                                                    @endforeach
                                                </select>
                                            @elseif ($specAttr->type === AttributeType::Boolean)
                                                <select wire:model="specs.{{ $specAttr->code }}"
                                                        class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                                                    <option value="">— Select —</option>
                                                    <option value="yes">Yes</option>
                                                    <option value="no">No</option>
                                                </select>
                                            @elseif ($specAttr->type === AttributeType::Number)
                                                <input wire:model="specs.{{ $specAttr->code }}" type="number" step="any" placeholder="e.g. 5000"
                                                       class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                            @else
                                                <input wire:model="specs.{{ $specAttr->code }}" type="text" placeholder="Enter {{ $specAttr->name }}..."
                                                       class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        <div class="flex items-center justify-between pt-4 border-t border-zinc-100 dark:border-zinc-800">
                            <button type="button" wire:click="$set('currentStep', 2)" class="rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                                ← Back to Brand
                            </button>
                            <button type="submit" data-test="step3-next-btn" class="rounded-lg bg-zinc-900 px-5 py-2 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                                Continue to Variants & Images →
                            </button>
                        </div>
                    </form>
                </div>
            @endif

            <!-- STEP 4: VARIANTS MATRIX & IMAGES -->
            @if ($currentStep === 4)
                <div class="space-y-6">
                    <!-- Variant Options Matrix Selector -->
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
                        <div>
                            <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Step 4: Configure Variants</h3>
                            <p class="text-xs text-zinc-500">
                                Select options for variant attributes below to automatically generate combination rows.
                            </p>
                        </div>

                        @if ($this->selectedVerticalModel)
                            @php
                                $variantAttributes = $this->selectedVerticalModel->attributes()->where('is_variant', true)->get();
                            @endphp

                            @if ($variantAttributes->isNotEmpty())
                                <div class="space-y-4 pt-2">
                                    @foreach ($variantAttributes as $varAttr)
                                        <div class="rounded-lg border border-zinc-100 p-4 dark:border-zinc-800/80 bg-zinc-50/50 dark:bg-zinc-800/30">
                                            <h4 class="text-xs font-bold text-zinc-900 dark:text-white uppercase tracking-wider">
                                                {{ $varAttr->name }}
                                            </h4>
                                            <div class="mt-2 flex flex-wrap gap-2">
                                                @php
                                                    $draftProd = $draftProductId ? \App\Models\Product::with('variants')->find($draftProductId) : null;
                                                    $availableOpts = $draftProd ? $draftProd->availableOptionsForAttribute($varAttr) : $varAttr->activeOptions();
                                                @endphp
                                                @forelse ($availableOpts as $opt)
                                                    @php
                                                        $isRet = ! empty($opt['is_retired']);
                                                    @endphp
                                                    <label class="flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-xs font-medium text-zinc-700 cursor-pointer dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300 hover:bg-zinc-50 {{ $isRet ? 'border-amber-300 dark:border-amber-800/80' : '' }}">
                                                        <input type="checkbox"
                                                               wire:click="toggleVariantOption('{{ $varAttr->code }}', '{{ $opt['value'] }}')"
                                                               @checked($this->isVariantOptionSelected($varAttr->code, $opt['value']))
                                                               class="size-3.5 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800" />
                                                        <span>{{ $opt['label'] }}</span>
                                                        @if ($isRet)
                                                            <span class="text-[9px] font-bold text-amber-600 dark:text-amber-400">(Retired)</span>
                                                        @endif
                                                    </label>
                                                @empty
                                                    <p class="text-xs text-amber-600 dark:text-amber-400">
                                                        No active options configured for {{ $varAttr->name }} yet.
                                                    </p>
                                                @endforelse
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            @else
                                <div class="rounded-lg border border-amber-200 bg-amber-50/60 p-4 text-xs text-amber-900 dark:border-amber-900/50 dark:bg-amber-950/30 dark:text-amber-200">
                                    <div class="flex items-center gap-2 font-semibold">
                                        <svg class="size-4 shrink-0 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                                        </svg>
                                        No variant attributes configured for this vertical ({{ $this->selectedVerticalModel->name }})
                                    </div>
                                    <p class="mt-1.5 text-zinc-600 dark:text-zinc-400">
                                        Products in this category require variant dimensions (such as Size, Color, etc.). Please configure variant attributes with options in <strong>Admin &rarr; Catalog &rarr; Verticals &rarr; Attributes</strong>.
                                    </p>
                                </div>
                            @endif
                        @endif

                        @error('variantRows')
                            <p class="text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <!-- Batch Tools & Generated Grid -->
                    @if (! empty($variantRows))
                        <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
                            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 border-b border-zinc-100 dark:border-zinc-800 pb-4">
                                <div>
                                    <h4 class="text-sm font-bold text-zinc-900 dark:text-white">Generated Variant Combinations ({{ count($variantRows) }})</h4>
                                    <p class="text-xs text-zinc-400">Set SKU, MRP, Selling Price, and Stock for each variant.</p>
                                </div>

                                <!-- Batch Apply -->
                                <div class="flex items-center gap-2">
                                    <input wire:model="batch_mrp" type="number" placeholder="Batch MRP ₹" class="w-24 rounded-md border border-zinc-300 bg-white px-2 py-1 text-xs" />
                                    <input wire:model="batch_price" type="number" placeholder="Batch Price ₹" class="w-24 rounded-md border border-zinc-300 bg-white px-2 py-1 text-xs" />
                                    <input wire:model="batch_stock" type="number" placeholder="Batch Stock" class="w-20 rounded-md border border-zinc-300 bg-white px-2 py-1 text-xs" />
                                    <button wire:click="applyToAll" type="button" class="rounded-md bg-zinc-800 px-3 py-1 text-xs font-semibold text-white hover:bg-zinc-700">
                                        Apply to All
                                    </button>
                                </div>
                            </div>

                            <div class="overflow-x-auto">
                                <table class="w-full text-left text-xs">
                                    <thead class="bg-zinc-50 text-zinc-500 uppercase dark:bg-zinc-800/50">
                                        <tr>
                                            <th class="px-3 py-2">Variant</th>
                                            <th class="px-3 py-2">Seller SKU *</th>
                                            <th class="px-3 py-2">MRP (₹) *</th>
                                            <th class="px-3 py-2">Selling Price (₹) *</th>
                                            <th class="px-3 py-2">Stock *</th>
                                            <th class="px-3 py-2">Barcode</th>
                                            <th class="px-3 py-2 text-right">Action</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                                        @foreach ($variantRows as $idx => $row)
                                            <tr wire:key="row-{{ $idx }}" class="hover:bg-zinc-50 dark:hover:bg-zinc-800/50">
                                                <td class="px-3 py-2 font-mono font-medium text-zinc-900 dark:text-white">
                                                    {{ $row['key'] }}
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input wire:model="variantRows.{{ $idx }}.sku" type="text" placeholder="e.g. SKU-123"
                                                           class="w-36 rounded-md border border-zinc-300 px-2 py-1 text-xs font-mono dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                                    @error("variantRows.{$idx}.sku") <p class="text-[10px] text-red-600 dark:text-red-400 mt-0.5">{{ $message }}</p> @enderror
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input wire:model="variantRows.{{ $idx }}.mrp" type="number" step="0.01" min="1" placeholder="₹"
                                                           class="w-24 rounded-md border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                                    @error("variantRows.{$idx}.mrp") <p class="text-[10px] text-red-600 dark:text-red-400 mt-0.5">{{ $message }}</p> @enderror
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input wire:model.live.debounce.300ms="variantRows.{{ $idx }}.selling_price" type="number" step="0.01" min="1" placeholder="₹"
                                                           class="w-24 rounded-md border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                                    @error("variantRows.{$idx}.selling_price") <p class="text-[10px] text-red-600 dark:text-red-400 mt-0.5">{{ $message }}</p> @enderror

                                                    @php
                                                        $rowPrice = $variantRows[$idx]['selling_price'] ?? 0;
                                                        $rowWeight = ! empty($variantRows[$idx]['weight']) ? (float) $variantRows[$idx]['weight'] : null;
                                                        $rowPreview = $this->getFeePreview($rowPrice, $this->vertical_id, $rowWeight);
                                                    @endphp
                                                    @if ($rowPreview)
                                                        <div x-data="{ open: false }" class="mt-1">
                                                            <div class="flex items-center gap-1 text-[10px] text-emerald-600 dark:text-emerald-400 font-semibold">
                                                                <span>≈ {{ $rowPreview->netPayout->format() }}</span>
                                                                <button type="button" @click="open = !open" class="text-zinc-400 hover:text-zinc-600 font-normal underline">(?)</button>
                                                            </div>
                                                            <div x-show="open" x-cloak class="absolute z-20 mt-1 w-48 rounded-lg border border-zinc-200 bg-white p-2 text-[10px] shadow-lg space-y-1 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                                                <div class="flex justify-between"><span>Commission:</span><span class="text-red-600">-{{ $rowPreview->commission->format() }}</span></div>
                                                                <div class="flex justify-between"><span>Closing Fee:</span><span class="text-red-600">-{{ $rowPreview->closingFee->format() }}</span></div>
                                                                <div class="flex justify-between"><span>Shipping:</span><span class="text-red-600">-{{ $rowPreview->shippingFee->format() }}</span></div>
                                                                <div class="flex justify-between"><span>Taxes:</span><span class="text-red-600">-{{ $rowPreview->gstOnFees->add($rowPreview->tcs, $rowPreview->tds)->format() }}</span></div>
                                                                <div class="border-t pt-0.5 text-[9px] text-zinc-400 italic">Final amount depends on delivery location.</div>
                                                            </div>
                                                        </div>
                                                    @endif
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input wire:model="variantRows.{{ $idx }}.stock" type="number" min="0" placeholder="Qty"
                                                           class="w-20 rounded-md border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                                    @error("variantRows.{$idx}.stock") <p class="text-[10px] text-red-600 dark:text-red-400 mt-0.5">{{ $message }}</p> @enderror
                                                </td>
                                                <td class="px-3 py-2">
                                                    <input wire:model="variantRows.{{ $idx }}.barcode" type="text" placeholder="EAN / UPC"
                                                           class="w-28 rounded-md border border-zinc-300 px-2 py-1 text-xs font-mono dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                                </td>
                                                <td class="px-3 py-2 text-right">
                                                    <button type="button" wire:click="removeVariantRow({{ $idx }})"
                                                            class="text-zinc-400 hover:text-red-600 transition" title="Remove this variant">
                                                        <svg class="size-4 inline" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                                        </svg>
                                                    </button>
                                                </td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    @endif

                    <!-- Product Images Uploader -->
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
                        <div>
                            <h4 class="text-sm font-bold text-zinc-900 dark:text-white">Product Images (JPG, PNG, WEBP — Min 500x500, Max 4 MB)</h4>
                            <p class="text-xs text-zinc-400">Upload clean, high-resolution product photos showing multiple angles.</p>
                        </div>

                        <!-- Existing Saved Images -->
                        @if ($draftProductId && \App\Models\Product::find($draftProductId)?->images->isNotEmpty())
                            <div class="flex flex-wrap gap-3">
                                @foreach (\App\Models\Product::find($draftProductId)->images as $savedImg)
                                    <div class="relative group size-20 rounded-lg border border-zinc-200 overflow-hidden dark:border-zinc-700">
                                        <img src="{{ $savedImg->url() }}" alt="Product Image" class="size-full object-cover" />
                                        @if ($savedImg->is_primary)
                                            <span class="absolute bottom-0 inset-x-0 bg-emerald-600 text-white text-[9px] font-bold text-center py-0.5">Primary</span>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif

                        <input wire:model="uploadedImages" type="file" multiple accept="image/*"
                               class="block w-full text-xs text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-800 dark:file:text-zinc-300" />
                        <div wire:loading wire:target="uploadedImages" class="text-xs text-zinc-500">Uploading photos...</div>
                    </div>

                    <div class="flex items-center justify-between pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="button" wire:click="$set('currentStep', 3)" class="rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                            ← Back to Details
                        </button>
                        <button type="button" wire:click="saveStep4" data-test="step4-next-btn" class="rounded-lg bg-zinc-900 px-5 py-2 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                            Continue to Review →
                        </button>
                    </div>
                </div>
            @endif

            <!-- STEP 5: REVIEW, VALIDATION CHECKLIST & SUBMIT -->
            @if ($currentStep === 5)
                <div class="space-y-6">
                    <!-- Validation Checklist -->
                    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
                        <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Step 5: Review & Submit</h3>
                        <p class="text-xs text-zinc-500">Check your product details below. Verify all fields pass catalog quality requirements.</p>

                        <div class="rounded-lg border border-zinc-200 divide-y divide-zinc-200 dark:border-zinc-800 dark:divide-zinc-800 text-xs">
                            <div class="flex items-center justify-between p-3">
                                <div class="flex items-center gap-2">
                                    <span class="size-2 rounded-full {{ $vertical_id ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                                    <span>Vertical: <strong>{{ $this->selectedVerticalModel?->name ?? 'None' }}</strong></span>
                                </div>
                                <button type="button" wire:click="$set('currentStep', 1)" class="font-semibold text-emerald-600 hover:underline">Change</button>
                            </div>

                            <div class="flex items-center justify-between p-3">
                                <div class="flex items-center gap-2">
                                    <span class="size-2 rounded-full {{ $brand_id ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                                    <span>Brand: <strong>{{ $this->selectedBrandModel?->name ?? 'None' }}</strong></span>
                                </div>
                                <button type="button" wire:click="$set('currentStep', 2)" class="font-semibold text-emerald-600 hover:underline">Change</button>
                            </div>

                            <div class="flex items-center justify-between p-3">
                                <div class="flex items-center gap-2">
                                    <span class="size-2 rounded-full {{ strlen($title) >= 5 ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                                    <span>Title: <strong>{{ $title ?: 'Missing' }}</strong></span>
                                </div>
                                <button type="button" wire:click="$set('currentStep', 3)" class="font-semibold text-emerald-600 hover:underline">Edit</button>
                            </div>

                            <div class="flex items-center justify-between p-3">
                                <div class="flex items-center gap-2">
                                    <span class="size-2 rounded-full {{ ! empty($variantRows) ? 'bg-emerald-500' : 'bg-red-500' }}"></span>
                                    <span>Variants: <strong>{{ count($variantRows) }} combinations ready</strong></span>
                                </div>
                                <button type="button" wire:click="$set('currentStep', 4)" class="font-semibold text-emerald-600 hover:underline">Edit</button>
                            </div>
                        </div>

                        <!-- Customer Preview Card -->
                        <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800">
                            <h4 class="text-xs font-bold uppercase tracking-wider text-zinc-400">Customer Preview</h4>
                            <div class="mt-3 flex flex-col md:flex-row gap-6 rounded-xl border border-zinc-200 p-6 bg-zinc-50/50 dark:border-zinc-800 dark:bg-zinc-800/30">
                                <div class="size-36 rounded-lg bg-zinc-200 dark:bg-zinc-700 flex items-center justify-center font-bold text-zinc-400 text-xs">
                                    Product Photo
                                </div>

                                <div class="flex-1 space-y-2">
                                    <span class="text-xs font-bold uppercase tracking-wider text-zinc-400">{{ $this->selectedBrandModel?->name }}</span>
                                    <h3 class="text-base font-bold text-zinc-900 dark:text-white">{{ $title }}</h3>
                                    <p class="text-xs text-zinc-600 dark:text-zinc-300 line-clamp-2">{{ $description }}</p>

                                    @if (! empty($variantRows))
                                        <div class="pt-2 text-sm font-bold text-zinc-900 dark:text-white">
                                            ₹{{ number_format((float) ($variantRows[0]['selling_price'] ?: 0), 2) }}
                                            @if ((float) ($variantRows[0]['mrp'] ?: 0) > (float) ($variantRows[0]['selling_price'] ?: 0))
                                                <span class="text-xs font-normal line-through text-zinc-400 ml-2">₹{{ number_format((float) $variantRows[0]['mrp'], 2) }}</span>
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="flex items-center justify-between pt-6 border-t border-zinc-100 dark:border-zinc-800">
                            <button type="button" wire:click="autosaveDraft"
                                    class="rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                                Save as Draft
                            </button>
                            <button wire:click="submitToQc" type="button" data-test="submit-to-qc-btn"
                                    class="rounded-lg bg-emerald-600 px-6 py-2.5 text-xs font-bold text-white hover:bg-emerald-700 transition shadow-xs">
                                Submit Product for Admin QC →
                            </button>
                        </div>
                    </div>
                </div>
            @endif
        </div>
    @endif

    <!-- QUICK SELL MODAL (For existing catalog variant) -->
    @if ($showQuickSellModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="$set('showQuickSellModal', false)" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-md rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between pb-4 border-b border-zinc-100 dark:border-zinc-800">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">List Existing Product</h3>
                    <button wire:click="$set('showQuickSellModal', false)" type="button" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form wire:submit="submitQuickSell" class="mt-4 space-y-4">
                    <div>
                        <label for="q-sku" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Your Seller SKU *</label>
                        <input wire:model="quick_sku" type="text" id="q-sku" required
                               class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-mono dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('quick_sku') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label for="q-mrp" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">MRP (₹) *</label>
                            <input wire:model="quick_mrp" type="number" step="0.01" min="1" id="q-mrp" required placeholder="999.00"
                                   class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            @error('quick_mrp') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="q-price" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Selling Price (₹) *</label>
                            <input wire:model.live.debounce.300ms="quick_price" type="number" step="0.01" min="1" id="q-price" required placeholder="799.00"
                                   class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            @error('quick_price') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror

                            @php
                                $quickPreview = $this->getFeePreview($this->quick_price, $this->vertical_id);
                            @endphp
                            @if ($quickPreview)
                                <div x-data="{ open: false }" class="mt-2 text-xs">
                                    <div class="flex items-center justify-between text-[11px] text-emerald-600 dark:text-emerald-400 font-bold">
                                        <span>You'll receive ≈ {{ $quickPreview->netPayout->format() }}</span>
                                        <button type="button" @click="open = !open" class="text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 underline text-[10px] font-normal">
                                            <span x-text="open ? 'Hide' : 'Details'"></span>
                                        </button>
                                    </div>
                                    <div x-show="open" x-cloak class="mt-1.5 rounded-lg border border-zinc-200 bg-zinc-50 p-2 text-[10px] space-y-1 text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                        <div class="flex justify-between"><span>Commission ({{ number_format($quickPreview->commissionRateBps / 100, 2) }}%):</span><span class="font-mono text-red-600">-{{ $quickPreview->commission->format() }}</span></div>
                                        <div class="flex justify-between"><span>Closing Fee:</span><span class="font-mono text-red-600">-{{ $quickPreview->closingFee->format() }}</span></div>
                                        <div class="flex justify-between"><span>Shipping (National):</span><span class="font-mono text-red-600">-{{ $quickPreview->shippingFee->format() }}</span></div>
                                        <div class="flex justify-between"><span>Taxes on Fees & TDS:</span><span class="font-mono text-red-600">-{{ $quickPreview->gstOnFees->add($quickPreview->tcs, $quickPreview->tds)->format() }}</span></div>
                                        <div class="pt-1 border-t border-zinc-200 dark:border-zinc-700 text-zinc-400 italic">Final amount depends on delivery location.</div>
                                    </div>
                                </div>
                            @endif
                        </div>
                    </div>

                    <div>
                        <label for="q-stock" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Available Stock *</label>
                        <input wire:model="quick_stock" type="number" min="0" id="q-stock" required
                               class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('quick_stock') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="button" wire:click="$set('showQuickSellModal', false)" class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                            Cancel
                        </button>
                        <button type="submit" data-test="confirm-quick-sell-btn" class="rounded-lg bg-emerald-600 px-4 py-1.5 text-xs font-semibold text-white hover:bg-emerald-700 transition shadow-xs">
                            Start Selling (Live Immediately)
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- INLINE REQUEST BRAND MODAL -->
    @if ($showRequestBrandModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4" role="dialog" aria-modal="true">
            <div wire:click="$set('showRequestBrandModal', false)" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-md rounded-xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900">
                <div class="flex items-center justify-between pb-4 border-b border-zinc-100 dark:border-zinc-800">
                    <h3 class="text-lg font-bold text-zinc-900 dark:text-white">Request Brand Approval</h3>
                    <button wire:click="$set('showRequestBrandModal', false)" type="button" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        <svg class="size-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                </div>

                <form wire:submit="requestNewBrand" class="mt-4 space-y-4">
                    <div>
                        <label for="new-brand-name" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Brand Name *</label>
                        <input wire:model="new_brand_name" type="text" id="new-brand-name" required placeholder="e.g. MyBrand"
                               class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-1.5 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('new_brand_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Brand Logo (Optional)</label>
                        <input wire:model="new_brand_logo" type="file" accept="image/*" class="mt-1 block w-full text-xs text-zinc-500 file:mr-3 file:py-1 file:px-3 file:rounded-md file:border-0 file:bg-zinc-100 file:text-xs" />
                        @error('new_brand_logo') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end gap-3 pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="button" wire:click="$set('showRequestBrandModal', false)" class="rounded-lg border border-zinc-300 px-3 py-1.5 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                            Cancel
                        </button>
                        <button type="submit" class="rounded-lg bg-zinc-900 px-4 py-1.5 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 transition">
                            Submit Request
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>

