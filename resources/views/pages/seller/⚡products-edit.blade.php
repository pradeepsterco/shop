<?php

use App\Enums\BrandStatus;
use App\Enums\ListingStatus;
use App\Enums\ProductImageStatus;
use App\Enums\ProductQcStatus;
use App\Models\Brand;
use App\Models\Listing;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\SellerProfile;
use App\Services\VariantOfferValidator;
use App\Services\VariantSyncService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.seller')] #[Title('Edit Product')] class extends Component {
    use WithFileUploads;

    public Product $product;
    public bool $isCreator = false;

    // Content fields (only editable by creator)
    public string $title = '';
    public string $description = '';
    public array $highlights = [];
    public array $specs = [];
    public ?int $brand_id = null;

    // Listing fields (for each variant listed by this seller)
    public array $sellerListings = []; // [variant_id => [id, sku, mrp, selling_price, stock, status, variant_key]]

    // Image upload
    public array $newImages = [];

    public function mount(Product $product): void
    {
        /** @var \App\Models\User $user */
        $user = Auth::user();
        /** @var SellerProfile|null $seller */
        $seller = $user->sellerProfile;

        if (! $seller || $seller->status->value !== 'approved') {
            $this->redirect(route('seller.apply'), navigate: true);

            return;
        }

        $this->product = $product->load(['vertical.attributes', 'brand', 'variants.listings', 'images']);
        $this->isCreator = ($this->product->created_by === $seller->id);

        $this->title = $product->title;
        $this->description = $product->description ?? '';
        $this->highlights = ! empty($product->highlights) ? $product->highlights : [''];
        $this->specs = $product->specs ?? [];
        $this->brand_id = $product->brand_id;

        // Load seller's listings for this product's variants
        $this->loadSellerListings($seller->id);
    }

    protected function loadSellerListings(int $sellerId): void
    {
        $this->sellerListings = [];

        foreach ($this->product->variants as $variant) {
            $listing = $variant->listings->where('seller_profile_id', $sellerId)->first();

            $this->sellerListings[$variant->id] = [
                'id' => $listing ? $listing->id : null,
                'variant_id' => $variant->id,
                'variant_key' => $variant->variant_key,
                'upid' => $variant->upid,
                'sku' => $listing ? $listing->sku : '',
                'mrp' => $listing ? (string) $listing->mrpInRupees() : '',
                'selling_price' => $listing ? (string) $listing->sellingPriceInRupees() : '',
                'stock' => $listing ? (string) $listing->stock : '0',
                'status' => $listing ? $listing->status->value : 'active',
                'is_listed' => $listing !== null,
            ];
        }
    }

    public function saveListingOffers(): void
    {
        /** @var SellerProfile $seller */
        $seller = Auth::user()->sellerProfile;

        $validator = new VariantOfferValidator();
        $validationResult = $validator->validateRows($this->sellerListings, $seller->id, $this->product, requireAtLeastOneActive: true);

        if (! empty($validationResult['errors'])) {
            foreach ($validationResult['errors'] as $key => $msg) {
                if ($key === 'general') {
                    $this->addError('general', $msg);
                } else {
                    $this->addError("sellerListings.{$key}", $msg);
                }
            }

            return;
        }

        $syncService = new VariantSyncService();
        $syncService->sync($this->product, $seller, $validationResult['validRows']);

        $this->product->refresh();
        $this->loadSellerListings($seller->id);
        session()->flash('status', 'Your pricing, stock, and offer details updated successfully.');
    }

    public function removeVariant(int $variantId): void
    {
        /** @var SellerProfile $seller */
        $seller = Auth::user()->sellerProfile;

        try {
            $syncService = new VariantSyncService();
            $result = $syncService->removeVariant($this->product, $seller, $variantId, Auth::user());

            $this->product->refresh();
            $this->loadSellerListings($seller->id);
            session()->flash('status', $result['message']);
        } catch (\DomainException $e) {
            $this->addError('general', $e->getMessage());
        }
    }

    public function saveCatalogContent(): void
    {
        if (! $this->isCreator) {
            abort(403, 'Only the product creator can edit catalog specifications.');
        }

        $this->validate([
            'title' => ['required', 'string', 'min:5', 'max:255'],
            'description' => ['required', 'string', 'min:10', 'max:3000'],
            'brand_id' => ['required', 'exists:brands,id'],
        ]);

        $cleanHighlights = array_values(array_filter($this->highlights, fn ($h) => trim($h) !== ''));

        // Upload any new images
        if (! empty($this->newImages)) {
            foreach ($this->newImages as $idx => $img) {
                $path = $img->store('product-images', 'public');
                ProductImage::create([
                    'product_id' => $this->product->id,
                    'path' => $path,
                    'sort_order' => $idx + 10,
                    'is_primary' => false,
                    'status' => $this->product->qc_status === ProductQcStatus::Approved ? ProductImageStatus::PendingAdd : ProductImageStatus::Live,
                ]);
            }
            $this->newImages = [];
        }

        // If product is already Approved, store edits in pending_changes and mark qc_pending
        if ($this->product->qc_status === ProductQcStatus::Approved) {
            $this->product->update([
                'pending_changes' => [
                    'title' => $this->title,
                    'description' => $this->description,
                    'highlights' => $cleanHighlights,
                    'specs' => $this->specs,
                    'brand_id' => $this->brand_id,
                ],
                'qc_status' => ProductQcStatus::QcPending,
                'qc_notes' => null,
            ]);

            session()->flash('status', 'Content edits submitted to QC. Current version remains live to customers until approved.');
        } else {
            // If Draft or Rejected, apply directly
            $this->product->update([
                'title' => $this->title,
                'description' => $this->description,
                'highlights' => $cleanHighlights,
                'specs' => $this->specs,
                'brand_id' => $this->brand_id,
                'qc_status' => ProductQcStatus::QcPending,
                'qc_notes' => null,
            ]);

            session()->flash('status', 'Product updated and resubmitted for Admin QC review.');
        }

        $this->product->flagNeedsUpdate();
        $this->product->refresh();
    }

    public function removeImage(int $imageId): void
    {
        $image = ProductImage::where('product_id', $this->product->id)->findOrFail($imageId);

        if ($this->product->qc_status === ProductQcStatus::Approved) {
            // Mark for removal upon next approval
            $image->update(['status' => ProductImageStatus::PendingRemove]);
            $this->product->update(['qc_status' => ProductQcStatus::QcPending]);
            session()->flash('status', 'Image marked for removal (pending QC approval).');
        } else {
            $image->delete();
            session()->flash('status', 'Image deleted.');
        }

        $this->product->refresh();
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <div class="flex items-center gap-2 text-xs text-zinc-500">
                <a href="{{ route('seller.dashboard') }}" wire:navigate class="hover:underline">Dashboard</a>
                <span>›</span>
                <span>{{ $product->vertical->name }}</span>
                <span>›</span>
                <span class="font-semibold text-zinc-900 dark:text-white">{{ $product->title }}</span>
            </div>
            <h2 class="mt-1 text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Edit Product</h2>
        </div>

        <div>
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold {{ $product->qc_status === ProductQcStatus::Approved ? 'bg-green-50 text-green-700 dark:bg-green-950 dark:text-green-300 border border-green-200' : ($product->qc_status === ProductQcStatus::QcPending ? 'bg-amber-50 text-amber-700 dark:bg-amber-950 dark:text-amber-300 border border-amber-200' : 'bg-zinc-100 text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300') }}">
                Status: {{ $product->qc_status->label() }}
            </span>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-900/50 dark:bg-green-950/30 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    <!-- Needs Update Banner -->
    @if ($product->needs_update)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 text-xs text-amber-900 dark:border-amber-900/60 dark:bg-amber-950/40 dark:text-amber-200 space-y-2">
            <div class="flex items-center gap-2 font-bold text-sm text-amber-800 dark:text-amber-300">
                <svg class="size-5 shrink-0 text-amber-600 dark:text-amber-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
                </svg>
                Action Required: This product needs updates before it can be listed or approved
            </div>
            <p class="text-xs text-amber-700 dark:text-amber-300">
                The following variant integrity issues were detected and must be corrected:
            </p>
            <ul class="list-disc list-inside space-y-1 font-medium">
                @foreach ($product->needs_update_reason ?? [] as $issue)
                    <li>{{ $issue['message'] ?? 'Variant issue detected' }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Rejection Notes Alert -->
    @if ($product->qc_status === ProductQcStatus::Rejected && $product->qc_notes)
        <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-xs text-red-800 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
            <strong class="font-bold">Admin QC Feedback:</strong> {{ $product->qc_notes }}
        </div>
    @endif

    <!-- Non-Creator Info Alert -->
    @if (! $isCreator)
        <div class="rounded-xl border border-blue-200 bg-blue-50 p-4 text-xs text-blue-800 dark:border-blue-900/50 dark:bg-blue-950/30 dark:text-blue-300">
            <strong>Shared Catalog Product:</strong> You are editing your selling offers (price, stock, SKU) for this product. Universal catalog details are managed by the original creator.
        </div>
    @endif

    <!-- PART 1: SELLER LISTING OFFERS (Price, Stock, SKU) -->
    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-4">
        <div>
            <h3 class="text-base font-bold text-zinc-900 dark:text-white">Your Listing Offers & Inventory</h3>
            <p class="text-xs text-zinc-500">Price and stock changes take effect immediately without requiring catalog QC.</p>
        </div>

        <form wire:submit="saveListingOffers" class="space-y-4">
            <div class="overflow-x-auto">
                <table class="w-full text-left text-xs">
                    <thead class="bg-zinc-50 text-zinc-500 uppercase dark:bg-zinc-800/50">
                        <tr>
                            <th class="px-3 py-2">Variant / UPID</th>
                            <th class="px-3 py-2">Seller SKU *</th>
                            <th class="px-3 py-2">MRP (₹) *</th>
                            <th class="px-3 py-2">Selling Price (₹) *</th>
                            <th class="px-3 py-2">Stock *</th>
                            <th class="px-3 py-2">Status</th>
                            <th class="px-3 py-2 text-right">Action</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                        @php
                            $flaggedVariantIds = collect($product->needs_update_reason ?? [])->pluck('variant_id')->filter()->map(fn ($id) => (int) $id)->all();
                        @endphp
                        @foreach ($sellerListings as $vId => $listing)
                            @php
                                $hasIssue = in_array((int) $vId, $flaggedVariantIds, true);
                            @endphp
                            <tr wire:key="offer-{{ $vId }}" class="{{ $hasIssue ? 'bg-amber-50/70 dark:bg-amber-950/40 border-l-4 border-amber-500' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800/50' }}">
                                <td class="px-3 py-2">
                                    <div class="font-mono font-medium text-zinc-900 dark:text-white">{{ $listing['variant_key'] }}</div>
                                    <div class="font-mono text-[10px] text-zinc-400">{{ $listing['upid'] }}</div>
                                    @if ($hasIssue)
                                        <span class="inline-flex items-center rounded px-1.5 py-0.5 text-[10px] font-semibold bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200 mt-1">Issue Flagged</span>
                                    @endif
                                </td>
                                <td class="px-3 py-2">
                                    <input wire:model="sellerListings.{{ $vId }}.sku" type="text"
                                           class="w-36 rounded-md border border-zinc-300 px-2 py-1 text-xs font-mono dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                    @error("sellerListings.{$vId}.sku") <p class="text-[10px] text-red-600 dark:text-red-400 mt-0.5">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-3 py-2">
                                    <input wire:model="sellerListings.{{ $vId }}.mrp" type="number" step="0.01" min="1"
                                           class="w-24 rounded-md border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                    @error("sellerListings.{$vId}.mrp") <p class="text-[10px] text-red-600 dark:text-red-400 mt-0.5">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-3 py-2">
                                    <input wire:model="sellerListings.{{ $vId }}.selling_price" type="number" step="0.01" min="1"
                                           class="w-24 rounded-md border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                    @error("sellerListings.{$vId}.selling_price") <p class="text-[10px] text-red-600 dark:text-red-400 mt-0.5">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-3 py-2">
                                    <input wire:model="sellerListings.{{ $vId }}.stock" type="number" min="0"
                                           class="w-20 rounded-md border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                    @error("sellerListings.{$vId}.stock") <p class="text-[10px] text-red-600 dark:text-red-400 mt-0.5">{{ $message }}</p> @enderror
                                </td>
                                <td class="px-3 py-2">
                                    <select wire:model="sellerListings.{{ $vId }}.status"
                                            class="rounded-md border border-zinc-300 px-2 py-1 text-xs dark:border-zinc-700 dark:bg-zinc-800 dark:text-white">
                                        <option value="active">Active</option>
                                        <option value="inactive">Inactive</option>
                                    </select>
                                </td>
                                <td class="px-3 py-2 text-right">
                                    <button type="button" wire:click="removeVariant({{ $vId }})"
                                            wire:confirm="Removing this variant listing will deactivate it and hide it from customers. Are you sure you want to proceed?"
                                            class="text-zinc-400 hover:text-red-600 transition p-1" title="Remove variant offer">
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

            @error('general')
                <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-xs text-red-700 dark:border-red-900/50 dark:bg-red-950/30 dark:text-red-300">
                    {{ $message }}
                </div>
            @enderror

            <div class="flex justify-end pt-3">
                <button type="submit" data-test="save-offers-btn" class="rounded-lg bg-zinc-900 px-4 py-2 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 transition shadow-xs">
                    Save Offers & Stock
                </button>
            </div>
        </form>
    </div>

    <!-- PART 2: CATALOG SPECIFICATIONS (Only visible/editable for creator) -->
    @if ($isCreator)
        <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-6">
            <div>
                <h3 class="text-base font-bold text-zinc-900 dark:text-white">Catalog Details & Specifications</h3>
                <p class="text-xs text-zinc-500">
                    @if ($product->qc_status === ProductQcStatus::Approved)
                        Changes made here will be placed in pending QC; the current live catalog content stays visible until approved.
                    @else
                        Edits will be submitted for Admin QC review upon saving.
                    @endif
                </p>
            </div>

            <form wire:submit="saveCatalogContent" class="space-y-4">
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Product Title *</label>
                    <input wire:model="title" type="text" required
                           class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                    @error('title') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Description *</label>
                    <textarea wire:model="description" rows="3" required
                              class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"></textarea>
                    @error('description') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>

                <!-- Existing Images -->
                <div>
                    <label class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Product Images</label>
                    <div class="mt-2 flex flex-wrap gap-3">
                        @foreach ($product->images as $img)
                            <div class="relative group size-20 rounded-lg border border-zinc-200 overflow-hidden dark:border-zinc-700">
                                <img src="{{ $img->url() }}" alt="Image" class="size-full object-cover" />
                                <button wire:click="removeImage({{ $img->id }})" type="button"
                                        class="absolute top-1 right-1 size-5 bg-red-600 text-white rounded-full flex items-center justify-center text-[10px] opacity-0 group-hover:opacity-100 transition">
                                    ✕
                                </button>
                                @if ($img->status === ProductImageStatus::PendingAdd)
                                    <span class="absolute bottom-0 inset-x-0 bg-amber-500 text-white text-[8px] text-center font-bold">Pending Add</span>
                                @elseif ($img->status === ProductImageStatus::PendingRemove)
                                    <span class="absolute bottom-0 inset-x-0 bg-red-500 text-white text-[8px] text-center font-bold">Pending Remove</span>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <input wire:model="newImages" type="file" multiple accept="image/*" class="mt-3 block text-xs text-zinc-500" />
                </div>

                <div class="flex justify-end pt-3">
                    <button type="submit" data-test="save-catalog-btn" class="rounded-lg bg-emerald-600 px-4 py-2 text-xs font-semibold text-white hover:bg-emerald-700 transition shadow-xs">
                        Save & Submit Content to QC
                    </button>
                </div>
            </form>
        </div>
    @endif
</div>

