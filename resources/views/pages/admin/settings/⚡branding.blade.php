<?php

use App\Branding\Contracts\BrandingLookupInterface;
use App\Branding\DTOs\BrandingData;
use App\Branding\Models\BrandingSetting;
use App\Branding\Services\BrandingService;
use App\Services\AdminActivityLogger;
use App\Support\AdminPermissions;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.admin')] #[Title('Marketplace Branding Settings')] class extends Component {
    use WithFileUploads;

    public string $marketplace_name = '';
    public string $short_name = '';
    public string $seller_area_name = '';
    public string $admin_area_name = '';
    public string $support_email = '';

    /**
     * Temporary upload holders per slot.
     * @var array<string, mixed>
     */
    public array $uploads = [];

    /**
     * Track active tab: 'general' or 'logos'.
     */
    public string $activeTab = 'general';

    public function mount(): void
    {
        $this->authorize(AdminPermissions::BRANDING_VIEW);

        $this->loadSettings();
    }

    public function loadSettings(): void
    {
        /** @var BrandingService $branding */
        $branding = app(BrandingService::class);
        $settings = $branding->getSettings();

        $this->marketplace_name = $settings->marketplaceName;
        $this->short_name = $settings->shortName;
        $this->seller_area_name = $settings->sellerAreaName;
        $this->admin_area_name = $settings->adminAreaName;
        $this->support_email = $settings->supportEmail ?? '';
    }

    public function saveGeneral(): void
    {
        $this->authorize(AdminPermissions::BRANDING_MANAGE);

        $validated = $this->validate([
            'marketplace_name' => ['required', 'string', 'max:100'],
            'short_name' => ['nullable', 'string', 'max:50'],
            'seller_area_name' => ['nullable', 'string', 'max:100'],
            'admin_area_name' => ['nullable', 'string', 'max:100'],
            'support_email' => ['nullable', 'email', 'max:150'],
        ]);

        /** @var BrandingService $branding */
        $branding = app(BrandingService::class);
        $setting = $branding->updateGeneralSettings($validated);

        AdminActivityLogger::log('branding.general_updated', $setting, [
            'marketplace_name' => $this->marketplace_name,
            'short_name' => $this->short_name,
            'seller_area_name' => $this->seller_area_name,
            'admin_area_name' => $this->admin_area_name,
            'support_email' => $this->support_email,
        ]);

        session()->flash('status', 'General branding settings updated successfully.');
    }

    public function saveLogo(string $slot): void
    {
        $this->authorize(AdminPermissions::BRANDING_MANAGE);

        if (! isset($this->uploads[$slot]) || ! $this->uploads[$slot]) {
            throw ValidationException::withMessages([
                "uploads.{$slot}" => 'Please select an image file to upload.',
            ]);
        }

        $file = $this->uploads[$slot];

        /** @var BrandingService $branding */
        $branding = app(BrandingService::class);
        $storedPath = $branding->uploadLogo($slot, $file);

        $setting = BrandingSetting::first();
        AdminActivityLogger::log('branding.logo_uploaded', $setting, [
            'slot' => $slot,
            'path' => $storedPath,
        ]);

        unset($this->uploads[$slot]);

        session()->flash('status', 'Logo updated successfully for '.str_replace('_', ' ', $slot).'.');
    }

    public function resetLogo(string $slot): void
    {
        $this->authorize(AdminPermissions::BRANDING_MANAGE);

        /** @var BrandingService $branding */
        $branding = app(BrandingService::class);
        $branding->resetLogo($slot);

        $setting = BrandingSetting::first();
        AdminActivityLogger::log('branding.logo_reset', $setting, [
            'slot' => $slot,
        ]);

        unset($this->uploads[$slot]);

        session()->flash('status', 'Logo reset to default for '.str_replace('_', ' ', $slot).'.');
    }

    public function clearUpload(string $slot): void
    {
        unset($this->uploads[$slot]);
    }
}; ?>

<div class="space-y-6">
    <!-- Header -->
    <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between border-b border-zinc-200 pb-5 dark:border-zinc-800">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Branding & White-Label Settings</h1>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">
                Customize marketplace names, logos for all dashboard areas, email branding, and favicons.
            </p>
        </div>
        <div class="flex items-center gap-2">
            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700 ring-1 ring-emerald-600/20 dark:bg-emerald-950/40 dark:text-emerald-300">
                Live Deployment: {{ brand_name() }}
            </span>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-medium text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950/40 dark:text-emerald-300">
            {{ session('status') }}
        </div>
    @endif

    <!-- Navigation Tabs -->
    <div class="flex border-b border-zinc-200 dark:border-zinc-800 gap-6">
        <button type="button"
                wire:click="$set('activeTab', 'general')"
                class="pb-3 text-sm font-semibold transition border-b-2 cursor-pointer {{ $activeTab === 'general' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
            Marketplace Names & Identity
        </button>
        <button type="button"
                wire:click="$set('activeTab', 'logos')"
                class="pb-3 text-sm font-semibold transition border-b-2 cursor-pointer {{ $activeTab === 'logos' ? 'border-zinc-900 text-zinc-900 dark:border-white dark:text-white' : 'border-transparent text-zinc-500 hover:text-zinc-700 dark:text-zinc-400 dark:hover:text-zinc-200' }}">
            Logos & Favicon
        </button>
    </div>

    <!-- Tab 1: Marketplace Names & Identity -->
    @if ($activeTab === 'general')
        <form wire:submit="saveGeneral" class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-6">
            <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                <div>
                    <label for="marketplace_name" class="block text-xs font-bold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">
                        Marketplace Name <span class="text-red-500">*</span>
                    </label>
                    <input type="text"
                           id="marketplace_name"
                           wire:model="marketplace_name"
                           class="mt-1.5 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                           placeholder="e.g. Khilauna"
                           required />
                    <p class="mt-1 text-xs text-zinc-500">The primary brand name displayed across storefronts, emails, and footers.</p>
                    @error('marketplace_name') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="short_name" class="block text-xs font-bold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">
                        Short Name / Monogram
                    </label>
                    <input type="text"
                           id="short_name"
                           wire:model="short_name"
                           class="mt-1.5 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                           placeholder="e.g. Khilauna" />
                    <p class="mt-1 text-xs text-zinc-500">Shortened label used in compact mobile bars and icons.</p>
                    @error('short_name') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="seller_area_name" class="block text-xs font-bold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">
                        Seller Area Name
                    </label>
                    <input type="text"
                           id="seller_area_name"
                           wire:model="seller_area_name"
                           class="mt-1.5 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                           placeholder="Default: {Name} Seller Center" />
                    <p class="mt-1 text-xs text-zinc-500">Defaults to "{Marketplace Name} Seller Center" if left blank.</p>
                    @error('seller_area_name') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>

                <div>
                    <label for="admin_area_name" class="block text-xs font-bold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">
                        Admin Area Name
                    </label>
                    <input type="text"
                           id="admin_area_name"
                           wire:model="admin_area_name"
                           class="mt-1.5 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                           placeholder="Default: {Name} Admin Panel" />
                    <p class="mt-1 text-xs text-zinc-500">Defaults to "{Marketplace Name} Admin Panel" if left blank.</p>
                    @error('admin_area_name') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>

                <div class="md:col-span-2">
                    <label for="support_email" class="block text-xs font-bold uppercase tracking-wider text-zinc-600 dark:text-zinc-400">
                        Marketplace Support Email (Optional)
                    </label>
                    <input type="email"
                           id="support_email"
                           wire:model="support_email"
                           class="mt-1.5 block w-full max-w-md rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"
                           placeholder="support@example.com" />
                    @error('support_email') <span class="text-xs text-red-600">{{ $message }}</span> @enderror
                </div>
            </div>

            <div class="flex justify-end pt-4 border-t border-zinc-200 dark:border-zinc-800">
                <button type="submit"
                        class="inline-flex items-center gap-2 rounded-lg bg-zinc-900 px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200 transition cursor-pointer">
                    Save Changes
                </button>
            </div>
        </form>
    @endif

    <!-- Tab 2: Logos & Favicon -->
    @if ($activeTab === 'logos')
        @php
            $branding = app(\App\Branding\Contracts\BrandingLookupInterface::class)->getSettings();
            $logoSections = [
                'default' => [
                    'title' => 'Default Marketplace Logo',
                    'desc' => 'Primary fallback logo used when an area-specific logo is not uploaded.',
                    'slots' => ['default_logo_light' => 'Light Background', 'default_logo_dark' => 'Dark Background'],
                ],
                'storefront' => [
                    'title' => 'Storefront Header Logo',
                    'desc' => 'Displayed on public catalog, product pages, and checkout navigation.',
                    'slots' => ['storefront_logo_light' => 'Light Background', 'storefront_logo_dark' => 'Dark Background'],
                ],
                'customer' => [
                    'title' => 'Customer Dashboard Logo',
                    'desc' => 'Displayed in the customer account area sidebar and mobile navigation drawer.',
                    'slots' => ['customer_logo_light' => 'Light Background', 'customer_logo_dark' => 'Dark Background'],
                ],
                'seller' => [
                    'title' => 'Seller Center Logo',
                    'desc' => 'Displayed in the seller onboarding, orders, and product management portal.',
                    'slots' => ['seller_logo_light' => 'Light Background', 'seller_logo_dark' => 'Dark Background'],
                ],
                'admin' => [
                    'title' => 'Admin Panel Logo',
                    'desc' => 'Displayed on the admin login screen, sidebar header, and admin navigation.',
                    'slots' => ['admin_logo_light' => 'Light Background', 'admin_logo_dark' => 'Dark Background'],
                ],
                'email' => [
                    'title' => 'Email Header Logo',
                    'desc' => 'Displayed at the top of customer, seller, and staff transactional emails (Light background only).',
                    'slots' => ['email_logo_light' => 'Email Header (Light Only)'],
                ],
                'favicon' => [
                    'title' => 'Marketplace Favicon',
                    'desc' => 'Browser tab icon. Must be square (e.g. 32×32, 64×64, 128×128, 512×512 px).',
                    'slots' => ['favicon' => 'Square Favicon'],
                ],
            ];
        @endphp

        <div class="space-y-6">
            @foreach ($logoSections as $sectionKey => $section)
                <div class="rounded-2xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <div class="mb-4">
                        <h2 class="text-base font-bold text-zinc-900 dark:text-white">{{ $section['title'] }}</h2>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $section['desc'] }}</p>
                    </div>

                    <div class="grid grid-cols-1 gap-6 md:grid-cols-2">
                        @foreach ($section['slots'] as $slotKey => $slotLabel)
                            @php
                                $existingPath = $branding->logos[$slotKey] ?? ($slotKey === 'favicon' ? $branding->faviconPath : null);
                                $hasExisting = filled($existingPath);
                                $pendingUpload = $uploads[$slotKey] ?? null;
                            @endphp

                            <div class="rounded-xl border border-zinc-200 p-4 dark:border-zinc-800 bg-zinc-50/50 dark:bg-zinc-900/50 flex flex-col justify-between space-y-4">
                                <div>
                                    <div class="flex items-center justify-between mb-2">
                                        <span class="text-xs font-bold uppercase tracking-wider text-zinc-700 dark:text-zinc-300">{{ $slotLabel }}</span>
                                        @if ($hasExisting)
                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold text-emerald-700 dark:bg-emerald-950/40 dark:text-emerald-300">
                                                Custom File Set
                                            </span>
                                        @else
                                            <span class="inline-flex items-center rounded-full bg-zinc-100 px-2 py-0.5 text-[10px] font-semibold text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">
                                                Default / Fallback
                                            </span>
                                        @endif
                                    </div>

                                    <!-- Live Preview Swatches (Light and Dark) -->
                                    <div class="grid grid-cols-2 gap-2 my-3">
                                        <!-- Light background preview -->
                                        <div class="flex flex-col items-center justify-center rounded-lg border border-zinc-200 bg-white p-3 h-20 text-center">
                                            @if ($pendingUpload)
                                                <img src="{{ $pendingUpload->temporaryUrl() }}" alt="Preview" class="max-h-12 max-w-full object-contain" />
                                            @elseif ($hasExisting)
                                                <img src="{{ Storage::disk('public')->url($existingPath) }}" alt="Current" class="max-h-12 max-w-full object-contain" />
                                            @else
                                                <span class="text-xs font-semibold text-zinc-400">No logo</span>
                                            @endif
                                            <span class="text-[9px] text-zinc-400 mt-1">Light BG</span>
                                        </div>

                                        <!-- Dark background preview -->
                                        <div class="flex flex-col items-center justify-center rounded-lg border border-zinc-700 bg-zinc-950 p-3 h-20 text-center">
                                            @if ($pendingUpload)
                                                <img src="{{ $pendingUpload->temporaryUrl() }}" alt="Preview" class="max-h-12 max-w-full object-contain" />
                                            @elseif ($hasExisting)
                                                <img src="{{ Storage::disk('public')->url($existingPath) }}" alt="Current" class="max-h-12 max-w-full object-contain" />
                                            @else
                                                <span class="text-xs font-semibold text-zinc-600">No logo</span>
                                            @endif
                                            <span class="text-[9px] text-zinc-500 mt-1">Dark BG</span>
                                        </div>
                                    </div>

                                    <!-- File input -->
                                    <div class="space-y-1">
                                        <input type="file"
                                               wire:model="uploads.{{ $slotKey }}"
                                               accept=".png,.jpg,.jpeg,.webp"
                                               class="block w-full text-xs text-zinc-500 file:mr-3 file:py-1 file:px-3 file:rounded-md file:border-0 file:text-xs file:font-semibold file:bg-zinc-200 file:text-zinc-800 hover:file:bg-zinc-300 dark:file:bg-zinc-700 dark:file:text-zinc-200 cursor-pointer" />
                                        <p class="text-[10px] text-zinc-400">PNG, JPG, WebP only (no SVG). Max 2 MB. Min 32px height.</p>
                                        @error("uploads.{$slotKey}") <span class="text-xs text-red-600 block">{{ $message }}</span> @enderror
                                        @error($slotKey) <span class="text-xs text-red-600 block">{{ $message }}</span> @enderror
                                    </div>
                                </div>

                                <!-- Action Buttons -->
                                <div class="flex items-center justify-between pt-2 border-t border-zinc-200 dark:border-zinc-800">
                                    <div>
                                        @if ($hasExisting)
                                            <button type="button"
                                                    wire:click="resetLogo('{{ $slotKey }}')"
                                                    wire:confirm="Reset {{ $slotLabel }} to default? This will delete the custom uploaded file."
                                                    class="text-xs font-medium text-red-600 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300 cursor-pointer">
                                                Reset to default
                                            </button>
                                        @endif
                                    </div>
                                    <div>
                                        @if ($pendingUpload)
                                            <button type="button"
                                                    wire:click="saveLogo('{{ $slotKey }}')"
                                                    class="inline-flex items-center gap-1 rounded-lg bg-zinc-900 px-3 py-1.5 text-xs font-semibold text-white shadow-xs hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-200 transition cursor-pointer">
                                                Upload & Save
                                            </button>
                                        @endif
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</div>

