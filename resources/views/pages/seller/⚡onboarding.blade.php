<?php

use App\Enums\SellerStatus;
use App\Models\SellerDocument;
use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

new #[Layout('layouts.seller')] #[Title('Seller Onboarding')] class extends Component {
    use WithFileUploads;

    public int $currentStep = 1;

    // Step 1: Store Info
    public string $store_name = '';
    public string $store_slug = '';
    public bool $is_slug_customized = false;
    public string $business_type = 'individual';
    public string $description = '';
    public mixed $logo = null;
    public ?string $existing_logo_path = null;

    // Step 2: Contact & Address
    public string $support_email = '';
    public string $support_phone = '';
    public string $pickup_address_line1 = '';
    public string $pickup_address_line2 = '';
    public string $pickup_city = '';
    public string $pickup_state = '';
    public string $pickup_postal_code = '';
    public string $pickup_country = 'India';

    // Step 3: Tax & Bank
    public string $pan = '';
    public string $gstin = '';
    public string $bank_account_holder = '';
    public string $bank_account_number = '';
    public string $bank_account_number_confirmation = '';
    public string $ifsc = '';

    // Step 4: Documents
    public mixed $id_proof = null;
    public mixed $address_proof = null;
    public ?string $existing_id_proof_name = null;
    public ?int $existing_id_proof_id = null;
    public ?string $existing_address_proof_name = null;
    public ?int $existing_address_proof_id = null;

    // Step 5: Terms
    public bool $terms_accepted = false;

    public ?SellerProfile $sellerProfile = null;

    public function mount(): void
    {
        /** @var User $user */
        $user = Auth::user();

        if ($user->isSeller()) {
            $this->redirect(route('seller.dashboard'), navigate: true);

            return;
        }

        $this->sellerProfile = $user->sellerProfile;

        if ($this->sellerProfile) {
            $this->loadProfileData();

            if (in_array($this->sellerProfile->status, [SellerStatus::Draft, SellerStatus::Rejected], true)) {
                $this->currentStep = max(1, min(5, (int) $this->sellerProfile->current_step));
            }
        } else {
            $this->support_email = $user->email;
        }
    }

    public function updatedStoreName(string $value): void
    {
        if ($this->sellerProfile?->status === SellerStatus::Approved) {
            return;
        }

        if (! $this->is_slug_customized) {
            if (blank($value)) {
                $this->store_slug = '';
            } else {
                $profileId = $this->sellerProfile?->id;
                $this->store_slug = SellerProfile::generateUniqueSlug($value, $profileId);
            }
        }
    }

    public function updatedStoreSlug(string $value): void
    {
        $clean = Str::slug($value);
        $this->store_slug = $clean;

        if (blank($value) || $clean === Str::slug($this->store_name)) {
            $this->is_slug_customized = false;
            if (blank($value) && filled($this->store_name)) {
                $profileId = $this->sellerProfile?->id;
                $this->store_slug = SellerProfile::generateUniqueSlug($this->store_name, $profileId);
            }
        } else {
            $this->is_slug_customized = true;
        }
    }

    public function generateSlug(): void
    {
        $profileId = $this->sellerProfile?->id;
        $this->store_slug = filled($this->store_name)
            ? SellerProfile::generateUniqueSlug($this->store_name, $profileId)
            : '';
        $this->is_slug_customized = false;
    }

    public function goToStep(int $step): void
    {
        if ($step >= 1 && $step <= 5) {
            $this->currentStep = $step;
        }
    }

    public function saveStep1(): void
    {
        $profileId = $this->sellerProfile?->id;

        if (blank($this->store_slug) && filled($this->store_name)) {
            $this->store_slug = SellerProfile::generateUniqueSlug($this->store_name, $profileId);
        } else {
            $this->store_slug = Str::slug($this->store_slug);
        }

        $this->validate([
            'store_name' => ['required', 'string', 'min:2', 'max:100'],
            'store_slug' => ['required', 'string', 'max:100', 'alpha_dash', Rule::unique('seller_profiles', 'store_slug')->ignore($profileId)],
            'business_type' => ['required', 'in:individual,company'],
            'description' => ['nullable', 'string', 'max:1000'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        $profile = $this->getOrCreateProfile();

        $logoPath = $profile->logo_path;
        if ($this->logo) {
            if ($logoPath && Storage::disk('public')->exists($logoPath)) {
                Storage::disk('public')->delete($logoPath);
            }
            $logoPath = $this->logo->store('seller-logos', 'public');
            $this->existing_logo_path = $logoPath;
            $this->logo = null;
        }

        $profile->fill([
            'store_name' => $this->store_name,
            'store_slug' => $this->store_slug,
            'business_type' => $this->business_type,
            'description' => $this->description,
            'logo_path' => $logoPath,
            'current_step' => 2,
        ]);
        $profile->save();

        $this->sellerProfile = $profile->fresh();
        $this->currentStep = 2;
    }

    public function saveStep2(): void
    {
        $this->validate([
            'support_email' => ['required', 'string', 'email', 'max:255'],
            'support_phone' => ['required', 'string', 'max:20'],
            'pickup_address_line1' => ['required', 'string', 'max:255'],
            'pickup_address_line2' => ['nullable', 'string', 'max:255'],
            'pickup_city' => ['required', 'string', 'max:100'],
            'pickup_state' => ['required', 'string', 'max:100'],
            'pickup_postal_code' => ['required', 'string', 'max:20'],
            'pickup_country' => ['required', 'string', 'max:100'],
        ]);

        $profile = $this->getOrCreateProfile();
        $profile->fill([
            'support_email' => $this->support_email,
            'support_phone' => $this->support_phone,
            'pickup_address_line1' => $this->pickup_address_line1,
            'pickup_address_line2' => $this->pickup_address_line2,
            'pickup_city' => $this->pickup_city,
            'pickup_state' => $this->pickup_state,
            'pickup_postal_code' => $this->pickup_postal_code,
            'pickup_country' => $this->pickup_country,
            'current_step' => 3,
        ]);
        $profile->save();

        $this->sellerProfile = $profile->fresh();
        $this->currentStep = 3;
    }

    public function saveStep3(): void
    {
        $this->pan = strtoupper(trim($this->pan));
        $this->ifsc = strtoupper(trim($this->ifsc));
        if ($this->gstin) {
            $this->gstin = strtoupper(trim($this->gstin));
        }

        $this->validate([
            'pan' => ['required', 'string', 'regex:/^[A-Z]{5}[0-9]{4}[A-Z]{1}$/'],
            'gstin' => ['nullable', 'string', 'regex:/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]{1}[1-9A-Z]{1}Z[0-9A-Z]{1}$/'],
            'bank_account_holder' => ['required', 'string', 'max:255'],
            'bank_account_number' => ['required', 'string', 'min:9', 'max:25', 'confirmed'],
            'ifsc' => ['required', 'string', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
        ]);

        $profile = $this->getOrCreateProfile();
        $profile->fill([
            'pan' => $this->pan,
            'gstin' => $this->gstin ?: null,
            'bank_account_holder' => $this->bank_account_holder,
            'bank_account_number' => $this->bank_account_number,
            'ifsc' => $this->ifsc,
            'current_step' => 4,
        ]);
        $profile->save();

        $this->sellerProfile = $profile->fresh();
        $this->currentStep = 4;
    }

    public function saveStep4(): void
    {
        $this->validate([
            'id_proof' => [$this->existing_id_proof_id ? 'nullable' : 'required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:4096'],
            'address_proof' => [$this->existing_address_proof_id ? 'nullable' : 'required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:4096'],
        ]);

        $profile = $this->getOrCreateProfile();

        if ($this->id_proof) {
            $existing = $profile->documents()->where('type', 'id_proof')->first();
            if ($existing) {
                $existing->delete();
            }

            $path = $this->id_proof->store('seller-documents', 'local');
            $doc = $profile->documents()->create([
                'type' => 'id_proof',
                'path' => $path,
                'original_name' => $this->id_proof->getClientOriginalName(),
            ]);

            $this->existing_id_proof_id = $doc->id;
            $this->existing_id_proof_name = $doc->original_name;
            $this->id_proof = null;
        }

        if ($this->address_proof) {
            $existing = $profile->documents()->where('type', 'address_proof')->first();
            if ($existing) {
                $existing->delete();
            }

            $path = $this->address_proof->store('seller-documents', 'local');
            $doc = $profile->documents()->create([
                'type' => 'address_proof',
                'path' => $path,
                'original_name' => $this->address_proof->getClientOriginalName(),
            ]);

            $this->existing_address_proof_id = $doc->id;
            $this->existing_address_proof_name = $doc->original_name;
            $this->address_proof = null;
        }

        $profile->current_step = 5;
        $profile->save();

        $this->sellerProfile = $profile->fresh(['documents']);
        $this->currentStep = 5;
    }

    public function submitApplication(): void
    {
        $this->validate([
            'terms_accepted' => ['accepted'],
        ]);

        $profile = $this->getOrCreateProfile();
        $profile->submit();

        $this->sellerProfile = $profile->fresh();
        session()->flash('success', 'Your seller application has been submitted for review!');
    }

    protected function getOrCreateProfile(): SellerProfile
    {
        if ($this->sellerProfile) {
            return $this->sellerProfile;
        }

        $this->sellerProfile = SellerProfile::create([
            'user_id' => Auth::id(),
            'store_name' => $this->store_name ?: 'My Store',
            'store_slug' => $this->store_slug ?: 'my-store-'.Auth::id(),
            'business_type' => $this->business_type ?: 'individual',
            'support_email' => $this->support_email ?: Auth::user()->email,
            'support_phone' => $this->support_phone ?: '9999999999',
            'pickup_address_line1' => $this->pickup_address_line1 ?: 'Pending',
            'pickup_city' => $this->pickup_city ?: 'Pending',
            'pickup_state' => $this->pickup_state ?: 'Pending',
            'pickup_postal_code' => $this->pickup_postal_code ?: '000000',
            'pickup_country' => $this->pickup_country ?: 'India',
            'pan' => $this->pan ?: 'ABCDE1234F',
            'bank_account_holder' => $this->bank_account_holder ?: Auth::user()->name,
            'bank_account_number' => $this->bank_account_number ?: '0000000000',
            'ifsc' => $this->ifsc ?: 'ABCD0123456',
            'status' => SellerStatus::Draft,
            'current_step' => 1,
        ]);

        return $this->sellerProfile;
    }

    protected function loadProfileData(): void
    {
        $p = $this->sellerProfile;
        if (! $p) {
            return;
        }

        $this->store_name = $p->store_name;
        $this->store_slug = $p->store_slug;
        $this->is_slug_customized = filled($p->store_slug) && filled($p->store_name) && $p->store_slug !== Str::slug($p->store_name);
        $this->business_type = $p->business_type;
        $this->description = $p->description ?? '';
        $this->existing_logo_path = $p->logo_path;

        $this->support_email = $p->support_email;
        $this->support_phone = $p->support_phone;
        $this->pickup_address_line1 = $p->pickup_address_line1;
        $this->pickup_address_line2 = $p->pickup_address_line2 ?? '';
        $this->pickup_city = $p->pickup_city;
        $this->pickup_state = $p->pickup_state;
        $this->pickup_postal_code = $p->pickup_postal_code;
        $this->pickup_country = $p->pickup_country ?: 'India';

        $this->pan = $p->pan;
        $this->gstin = $p->gstin ?? '';
        $this->bank_account_holder = $p->bank_account_holder;
        $this->bank_account_number = $p->bank_account_number;
        $this->bank_account_number_confirmation = $p->bank_account_number;
        $this->ifsc = $p->ifsc;

        $idDoc = $p->documents()->where('type', 'id_proof')->first();
        if ($idDoc) {
            $this->existing_id_proof_id = $idDoc->id;
            $this->existing_id_proof_name = $idDoc->original_name;
        }

        $addrDoc = $p->documents()->where('type', 'address_proof')->first();
        if ($addrDoc) {
            $this->existing_address_proof_id = $addrDoc->id;
            $this->existing_address_proof_name = $addrDoc->original_name;
        }
    }
}; ?>

<div class="max-w-4xl mx-auto space-y-8">
    @if ($this->sellerProfile && $this->sellerProfile->status === SellerStatus::Pending)
        <!-- Pending Review Screen -->
        <div class="rounded-2xl border border-amber-200 bg-amber-50/50 p-8 text-center dark:border-amber-900/50 dark:bg-amber-950/20 shadow-xs">
            <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-amber-100 text-amber-600 dark:bg-amber-900/40 dark:text-amber-400">
                <svg class="size-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                </svg>
            </div>
            <h2 class="mt-4 text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Application Under Review</h2>
            <p class="mx-auto mt-2 max-w-lg text-sm text-zinc-600 dark:text-zinc-400">
                Your application for <strong class="text-zinc-900 dark:text-white">{{ $this->sellerProfile->store_name }}</strong> was submitted on
                {{ $this->sellerProfile->submitted_at?->format('M j, Y H:i') ?? 'recently' }}. Our team is currently reviewing your documents and store information.
            </p>
            <div class="mt-6 flex justify-center gap-4">
                <a href="{{ route('dashboard') }}" wire:navigate class="rounded-lg bg-zinc-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                    Return to Customer Dashboard
                </a>
            </div>
        </div>

    @elseif ($this->sellerProfile && $this->sellerProfile->status === SellerStatus::Suspended)
        <!-- Suspended Account Screen -->
        <div class="rounded-2xl border border-red-200 bg-red-50/50 p-8 text-center dark:border-red-900/50 dark:bg-red-950/20 shadow-xs">
            <div class="mx-auto flex size-14 items-center justify-center rounded-full bg-red-100 text-red-600 dark:bg-red-900/40 dark:text-red-400">
                <svg class="size-7" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" />
                </svg>
            </div>
            <h2 class="mt-4 text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Seller Account Suspended</h2>
            <p class="mx-auto mt-2 max-w-lg text-sm text-zinc-600 dark:text-zinc-400">
                Your seller privileges have been temporarily suspended by the platform administration. Please contact our support team for assistance.
            </p>
            <div class="mt-6 flex justify-center gap-4">
                <a href="{{ route('dashboard') }}" wire:navigate class="rounded-lg bg-zinc-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                    Go to Customer Dashboard
                </a>
            </div>
        </div>

    @else
        <!-- Onboarding Wizard (Draft or Rejected) -->
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Seller Registration</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Complete the steps below to onboard your store and sell on our platform.</p>
        </div>

        @if ($this->sellerProfile && $this->sellerProfile->status === SellerStatus::Rejected)
            <div class="rounded-xl border border-red-200 bg-red-50 p-5 dark:border-red-900/50 dark:bg-red-950/30">
                <div class="flex items-start gap-3">
                    <svg class="size-5 shrink-0 text-red-600 dark:text-red-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                    </svg>
                    <div>
                        <h4 class="text-sm font-semibold text-red-800 dark:text-red-300">Previous Application Rejected</h4>
                        <p class="mt-1 text-sm text-red-700 dark:text-red-300">
                            <strong>Reason:</strong> {{ $this->sellerProfile->rejection_reason }}
                        </p>
                        <p class="mt-2 text-xs text-red-600 dark:text-red-400">
                            Please update the necessary information or documents below and resubmit your application.
                        </p>
                    </div>
                </div>
            </div>
        @endif

        <!-- Stepper Progress Bar -->
        <nav aria-label="Progress" class="rounded-xl border border-zinc-200 bg-white p-4 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
            <ol class="grid grid-cols-2 gap-4 sm:grid-cols-5 text-sm">
                @foreach ([1 => 'Store Info', 2 => 'Contact & Address', 3 => 'Tax & Bank', 4 => 'Documents', 5 => 'Review'] as $stepNumber => $stepLabel)
                    <li class="flex items-center gap-2">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold {{ $currentStep === $stepNumber ? 'bg-zinc-900 text-white dark:bg-white dark:text-zinc-900' : ($currentStep > $stepNumber ? 'bg-emerald-600 text-white' : 'bg-zinc-200 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400') }}">
                            @if ($currentStep > $stepNumber)
                                ✓
                            @else
                                {{ $stepNumber }}
                            @endif
                        </span>
                        <span class="text-xs font-medium {{ $currentStep === $stepNumber ? 'text-zinc-900 dark:text-white font-semibold' : 'text-zinc-500 dark:text-zinc-400' }}">
                            {{ $stepLabel }}
                        </span>
                    </li>
                @endforeach
            </ol>
        </nav>

        <!-- Step 1: Store Information -->
        @if ($currentStep === 1)
            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Step 1: Store Details</h3>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Tell us about your brand and business structure.</p>

                <form wire:submit="saveStep1" class="mt-6 space-y-5">
                    <div>
                        <label for="store_name" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Store Name *</label>
                        <input wire:model.live.debounce.300ms="store_name" type="text" id="store_name" required placeholder="e.g. Wonderland Toys"
                               class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('store_name') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <label for="store_slug" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Store URL Slug *</label>
                            @if ($is_slug_customized)
                                <button type="button" wire:click="generateSlug" class="text-xs font-medium text-emerald-600 hover:text-emerald-700 dark:text-emerald-400 dark:hover:text-emerald-300 transition">
                                    Reset to store name
                                </button>
                            @endif
                        </div>
                        <div class="mt-1 flex rounded-lg shadow-xs">
                            <span class="inline-flex items-center rounded-l-lg border border-r-0 border-zinc-300 bg-zinc-50 px-3 text-xs text-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-400">/stores/</span>
                            <input wire:model.blur="store_slug" type="text" id="store_slug" required placeholder="wonderland-toys"
                                   class="block w-full min-w-0 rounded-none rounded-r-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        </div>
                        @error('store_slug') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Business Type *</label>
                        <div class="mt-2 flex gap-6">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input wire:model="business_type" type="radio" value="individual" class="size-4 text-zinc-900 focus:ring-zinc-500 dark:text-white" />
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">Individual / Proprietorship</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input wire:model="business_type" type="radio" value="company" class="size-4 text-zinc-900 focus:ring-zinc-500 dark:text-white" />
                                <span class="text-sm text-zinc-700 dark:text-zinc-300">Registered Company / LLP</span>
                            </label>
                        </div>
                        @error('business_type') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="description" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Description</label>
                        <textarea wire:model="description" id="description" rows="3" placeholder="Briefly describe your products and store..."
                                  class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white"></textarea>
                        @error('description') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="logo" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Store Logo</label>
                        @if ($logo)
                            <div class="mt-2 flex items-center gap-3">
                                <img src="{{ $logo->temporaryUrl() }}" alt="Logo Preview" class="size-12 rounded-lg object-cover border border-zinc-200 dark:border-zinc-700" />
                                <span class="text-xs text-zinc-500">Selected logo preview</span>
                            </div>
                        @elseif ($existing_logo_path)
                            <div class="mt-2 flex items-center gap-3">
                                <img src="{{ Storage::disk('public')->url($existing_logo_path) }}" alt="Current Logo" class="size-12 rounded-lg object-cover border border-zinc-200 dark:border-zinc-700" />
                                <span class="text-xs text-zinc-500">Current logo saved</span>
                            </div>
                        @endif
                        <input wire:model="logo" type="file" id="logo" accept="image/*" class="mt-2 block w-full text-xs text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-800 dark:file:text-zinc-300" />
                        @error('logo') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex justify-end pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="submit" data-test="step1-next-button" class="rounded-lg bg-zinc-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                            Continue to Contact Details →
                        </button>
                    </div>
                </form>
            </div>
        @endif

        <!-- Step 2: Contact & Pickup Address -->
        @if ($currentStep === 2)
            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Step 2: Contact & Pickup Location</h3>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Where customers reach you and where courier partners pick up parcels.</p>

                <form wire:submit="saveStep2" class="mt-6 space-y-5">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label for="support_email" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Support Email *</label>
                            <input wire:model="support_email" type="email" id="support_email" required
                                   class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            @error('support_email') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="support_phone" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Support Phone *</label>
                            <input wire:model="support_phone" type="text" id="support_phone" required placeholder="e.g. 9876543210"
                                   class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            @error('support_phone') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label for="pickup_address_line1" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Address Line 1 *</label>
                        <input wire:model="pickup_address_line1" type="text" id="pickup_address_line1" required placeholder="Building, Street, Landmark"
                               class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('pickup_address_line1') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="pickup_address_line2" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Address Line 2 (Optional)</label>
                        <input wire:model="pickup_address_line2" type="text" id="pickup_address_line2" placeholder="Suite, unit, floor"
                               class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('pickup_address_line2') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-3">
                        <div>
                            <label for="pickup_city" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">City *</label>
                            <input wire:model="pickup_city" type="text" id="pickup_city" required
                                   class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            @error('pickup_city') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="pickup_state" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">State *</label>
                            <input wire:model="pickup_state" type="text" id="pickup_state" required
                                   class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            @error('pickup_state') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="pickup_postal_code" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Postal / PIN Code *</label>
                            <input wire:model="pickup_postal_code" type="text" id="pickup_postal_code" required
                                   class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            @error('pickup_postal_code') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label for="pickup_country" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Country *</label>
                        <input wire:model="pickup_country" type="text" id="pickup_country" required
                               class="mt-1 block w-full rounded-lg border border-zinc-300 bg-zinc-100 px-3 py-2 text-sm text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('pickup_country') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-center justify-between pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="button" wire:click="goToStep(1)" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                            ← Back
                        </button>
                        <button type="submit" data-test="step2-next-button" class="rounded-lg bg-zinc-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                            Continue to Tax & Bank Details →
                        </button>
                    </div>
                </form>
            </div>
        @endif

        <!-- Step 3: Tax & Bank Details -->
        @if ($currentStep === 3)
            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Step 3: Tax & Bank Information</h3>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">All sensitive identifiers and account numbers are encrypted in storage.</p>

                <form wire:submit="saveStep3" class="mt-6 space-y-5">
                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label for="pan" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">PAN Card Number *</label>
                            <input wire:model="pan" type="text" id="pan" required placeholder="ABCDE1234F" maxlength="10"
                                   class="mt-1 block w-full uppercase rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            <p class="mt-1 text-xs text-zinc-400">10-character alphanumeric PAN</p>
                            @error('pan') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="gstin" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">GSTIN (Optional)</label>
                            <input wire:model="gstin" type="text" id="gstin" placeholder="22AAAAA0000A1Z5" maxlength="15"
                                   class="mt-1 block w-full uppercase rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            <p class="mt-1 text-xs text-zinc-400">15-digit GSTIN</p>
                            @error('gstin') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>
                    </div>

                    <div>
                        <label for="bank_account_holder" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Bank Account Holder Name *</label>
                        <input wire:model="bank_account_holder" type="text" id="bank_account_holder" required placeholder="Name as per bank records"
                               class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('bank_account_holder') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                        <div>
                            <label for="bank_account_number" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Bank Account Number *</label>
                            <input wire:model="bank_account_number" type="password" id="bank_account_number" required placeholder="Account number"
                                   class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            @error('bank_account_number') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label for="bank_account_number_confirmation" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Confirm Account Number *</label>
                            <input wire:model="bank_account_number_confirmation" type="text" id="bank_account_number_confirmation" required placeholder="Re-enter account number"
                                   class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        </div>
                    </div>

                    <div>
                        <label for="ifsc" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Bank IFSC Code *</label>
                        <input wire:model="ifsc" type="text" id="ifsc" required placeholder="e.g. HDFC0001234" maxlength="11"
                               class="mt-1 block w-full uppercase rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        <p class="mt-1 text-xs text-zinc-400">11-character alphanumeric code</p>
                        @error('ifsc') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-center justify-between pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="button" wire:click="goToStep(2)" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                            ← Back
                        </button>
                        <button type="submit" data-test="step3-next-button" class="rounded-lg bg-zinc-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                            Continue to KYC Documents →
                        </button>
                    </div>
                </form>
            </div>
        @endif

        <!-- Step 4: KYC Documents -->
        @if ($currentStep === 4)
            <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Step 4: KYC Document Verification</h3>
                <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Upload your ID proof and address proof. Files are stored on a private encrypted disk and accessible only by you and administrators.</p>

                <form wire:submit="saveStep4" class="mt-6 space-y-6">
                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                        <label for="id_proof" class="block text-sm font-semibold text-zinc-900 dark:text-white">Identity Proof (PAN Card / Aadhaar / Passport) *</label>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Supported formats: PDF, JPG, PNG (Max 4 MB)</p>

                        @if ($existing_id_proof_id)
                            <div class="mt-3 flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                <span>Uploaded: <strong>{{ $existing_id_proof_name }}</strong></span>
                                <a href="{{ route('seller.documents.show', $existing_id_proof_id) }}" target="_blank" class="font-semibold text-emerald-600 hover:underline dark:text-emerald-400">Download</a>
                            </div>
                        @endif

                        <input wire:model="id_proof" type="file" id="id_proof" class="mt-3 block w-full text-xs text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-800 dark:file:text-zinc-300" />
                        @error('id_proof') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="rounded-lg border border-zinc-200 p-4 dark:border-zinc-800">
                        <label for="address_proof" class="block text-sm font-semibold text-zinc-900 dark:text-white">Address Proof (Electricity Bill / Bank Statement / Lease) *</label>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400">Supported formats: PDF, JPG, PNG (Max 4 MB)</p>

                        @if ($existing_address_proof_id)
                            <div class="mt-3 flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-2 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                                <span>Uploaded: <strong>{{ $existing_address_proof_name }}</strong></span>
                                <a href="{{ route('seller.documents.show', $existing_address_proof_id) }}" target="_blank" class="font-semibold text-emerald-600 hover:underline dark:text-emerald-400">Download</a>
                            </div>
                        @endif

                        <input wire:model="address_proof" type="file" id="address_proof" class="mt-3 block w-full text-xs text-zinc-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-xs file:font-semibold file:bg-zinc-100 file:text-zinc-700 hover:file:bg-zinc-200 dark:file:bg-zinc-800 dark:file:text-zinc-300" />
                        @error('address_proof') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-center justify-between pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="button" wire:click="goToStep(3)" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                            ← Back
                        </button>
                        <button type="submit" data-test="step4-next-button" class="rounded-lg bg-zinc-900 px-5 py-2.5 text-sm font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition shadow-xs">
                            Review & Submit →
                        </button>
                    </div>
                </form>
            </div>
        @endif

        <!-- Step 5: Review & Submit -->
        @if ($currentStep === 5)
            <div class="space-y-6">
                <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
                    <h3 class="text-lg font-semibold text-zinc-900 dark:text-white">Step 5: Review Application Summary</h3>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">Check your details before submitting for review.</p>

                    <div class="mt-6 divide-y divide-zinc-200 dark:divide-zinc-800 space-y-6">
                        <!-- Store Info -->
                        <div class="pt-4 first:pt-0">
                            <div class="flex items-center justify-between">
                                <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Store Information</h4>
                                <button type="button" wire:click="goToStep(1)" class="text-xs font-semibold text-emerald-600 hover:underline dark:text-emerald-400">Edit</button>
                            </div>
                            <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                <div><span class="text-zinc-500">Store Name:</span> <span class="font-medium text-zinc-900 dark:text-white">{{ $store_name }}</span></div>
                                <div><span class="text-zinc-500">Slug:</span> <span class="font-medium text-zinc-900 dark:text-white">/stores/{{ $store_slug }}</span></div>
                                <div><span class="text-zinc-500">Business Type:</span> <span class="font-medium capitalize text-zinc-900 dark:text-white">{{ $business_type }}</span></div>
                            </dl>
                        </div>

                        <!-- Contact & Pickup -->
                        <div class="pt-4">
                            <div class="flex items-center justify-between">
                                <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Contact & Pickup Address</h4>
                                <button type="button" wire:click="goToStep(2)" class="text-xs font-semibold text-emerald-600 hover:underline dark:text-emerald-400">Edit</button>
                            </div>
                            <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                <div><span class="text-zinc-500">Email:</span> <span class="font-medium text-zinc-900 dark:text-white">{{ $support_email }}</span></div>
                                <div><span class="text-zinc-500">Phone:</span> <span class="font-medium text-zinc-900 dark:text-white">{{ $support_phone }}</span></div>
                                <div class="sm:col-span-2"><span class="text-zinc-500">Address:</span> <span class="font-medium text-zinc-900 dark:text-white">{{ $pickup_address_line1 }}{{ $pickup_address_line2 ? ', '.$pickup_address_line2 : '' }}, {{ $pickup_city }}, {{ $pickup_state }} - {{ $pickup_postal_code }}, {{ $pickup_country }}</span></div>
                            </dl>
                        </div>

                        <!-- Tax & Bank -->
                        <div class="pt-4">
                            <div class="flex items-center justify-between">
                                <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Tax & Bank Details</h4>
                                <button type="button" wire:click="goToStep(3)" class="text-xs font-semibold text-emerald-600 hover:underline dark:text-emerald-400">Edit</button>
                            </div>
                            <dl class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-3 text-xs">
                                <div><span class="text-zinc-500">PAN:</span> <span class="font-medium uppercase text-zinc-900 dark:text-white">{{ $pan }}</span></div>
                                <div><span class="text-zinc-500">GSTIN:</span> <span class="font-medium uppercase text-zinc-900 dark:text-white">{{ $gstin ?: 'None provided' }}</span></div>
                                <div><span class="text-zinc-500">Account Holder:</span> <span class="font-medium text-zinc-900 dark:text-white">{{ $bank_account_holder }}</span></div>
                                <div><span class="text-zinc-500">IFSC:</span> <span class="font-medium uppercase text-zinc-900 dark:text-white">{{ $ifsc }}</span></div>
                            </dl>
                        </div>

                        <!-- Documents -->
                        <div class="pt-4">
                            <div class="flex items-center justify-between">
                                <h4 class="text-sm font-semibold text-zinc-900 dark:text-white">Uploaded Documents</h4>
                                <button type="button" wire:click="goToStep(4)" class="text-xs font-semibold text-emerald-600 hover:underline dark:text-emerald-400">Edit</button>
                            </div>
                            <ul class="mt-2 text-xs text-zinc-600 dark:text-zinc-400 space-y-1">
                                <li>✓ ID Proof: {{ $existing_id_proof_name ?? 'Uploaded' }}</li>
                                <li>✓ Address Proof: {{ $existing_address_proof_name ?? 'Uploaded' }}</li>
                            </ul>
                        </div>
                    </div>
                </div>

                <form wire:submit="submitApplication" class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900 space-y-5">
                    <div>
                        <label class="flex items-start gap-3 cursor-pointer">
                            <input wire:model="terms_accepted" type="checkbox" required class="mt-0.5 size-4 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:focus:ring-zinc-400" />
                            <span class="text-sm text-zinc-600 dark:text-zinc-400">
                                I hereby declare that all the information and documents submitted above are true and accurate. I agree to abide by the seller terms of service and marketplace policies.
                            </span>
                        </label>
                        @error('terms_accepted') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                    </div>

                    <div class="flex items-center justify-between pt-4 border-t border-zinc-100 dark:border-zinc-800">
                        <button type="button" wire:click="goToStep(4)" class="rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                            ← Back
                        </button>
                        <button type="submit" data-test="submit-application-button" class="rounded-lg bg-emerald-600 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700 transition shadow-xs">
                            Submit Application for Review
                        </button>
                    </div>
                </form>
            </div>
        @endif
    @endif
</div>

