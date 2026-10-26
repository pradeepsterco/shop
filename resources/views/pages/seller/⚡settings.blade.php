<?php

use App\Models\SellerProfile;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.seller')] #[Title('Seller Settings')] class extends Component {
    public string $bank_account_holder = '';
    public string $bank_account_number = '';
    public string $bank_account_number_confirmation = '';
    public string $ifsc = '';
    public string $current_password = '';

    public ?string $bank_updated_at = null;

    public function mount(): void
    {
        /** @var User $user */
        $user = Auth::user();
        $p = $user->sellerProfile;

        if (! $p) {
            $this->redirect(route('seller.apply'), navigate: true);

            return;
        }

        $this->bank_account_holder = $p->bank_account_holder;
        $this->bank_account_number = $p->bank_account_number;
        $this->bank_account_number_confirmation = $p->bank_account_number;
        $this->ifsc = $p->ifsc;
        $this->bank_updated_at = $p->bank_updated_at ? format_datetime($p->bank_updated_at) : null;
    }

    public function updateBankDetails(): void
    {
        $this->ifsc = strtoupper(trim($this->ifsc));

        $this->validate([
            'bank_account_holder' => ['required', 'string', 'max:255'],
            'bank_account_number' => ['required', 'string', 'min:9', 'max:25', 'confirmed'],
            'ifsc' => ['required', 'string', 'regex:/^[A-Z]{4}0[A-Z0-9]{6}$/'],
            'current_password' => ['required', 'string', 'current_password:web'],
        ]);

        /** @var User $user */
        $user = Auth::user();
        /** @var SellerProfile $profile */
        $profile = $user->sellerProfile;

        $profile->update([
            'bank_account_holder' => $this->bank_account_holder,
            'bank_account_number' => $this->bank_account_number,
            'ifsc' => $this->ifsc,
            'bank_updated_at' => now(),
        ]);

        $this->current_password = '';
        $this->bank_updated_at = $profile->fresh()->bank_updated_at ? format_datetime($profile->fresh()->bank_updated_at) : null;

        session()->flash('status', 'Bank details updated successfully. Platform administrators have been notified for re-verification.');
    }
}; ?>

<div class="space-y-6 max-w-3xl">
    <div>
        <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Bank & Payout Settings</h2>
        <p class="text-sm text-zinc-500 dark:text-zinc-400">Manage the bank account where marketplace sales payouts are deposited.</p>
    </div>

    <!-- Re-verification Advisory Note -->
    <div class="rounded-xl border border-amber-200 bg-amber-50 p-5 dark:border-amber-900/50 dark:bg-amber-950/30">
        <div class="flex items-start gap-3">
            <svg class="size-5 shrink-0 text-amber-600 dark:text-amber-400 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
            </svg>
            <div class="text-xs text-amber-800 dark:text-amber-300 space-y-1">
                <h4 class="font-semibold text-sm">Security & Re-verification Notice</h4>
                <p>
                    For anti-fraud compliance, updating your bank details will flag your account for administrative review.
                    <strong>Your store will remain active and approved</strong>, but upcoming payouts may be verified by our team.
                </p>
                @if ($bank_updated_at)
                    <p class="pt-1 font-medium text-amber-900 dark:text-amber-200">
                        Last bank update: {{ $bank_updated_at }}
                    </p>
                @endif
            </div>
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-900/50 dark:bg-green-950/30 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    <div class="rounded-xl border border-zinc-200 bg-white p-6 shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <form wire:submit="updateBankDetails" class="space-y-5">
            <div>
                <label for="bank_account_holder" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Account Beneficiary Name *</label>
                <input wire:model="bank_account_holder" type="text" id="bank_account_holder" required
                       class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                @error('bank_account_holder') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                <div>
                    <label for="bank_account_number" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">New Account Number *</label>
                    <input wire:model="bank_account_number" type="password" id="bank_account_number" required
                           class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                    @error('bank_account_number') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="bank_account_number_confirmation" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Confirm Account Number *</label>
                    <input wire:model="bank_account_number_confirmation" type="text" id="bank_account_number_confirmation" required
                           class="mt-1 block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                </div>
            </div>

            <div>
                <label for="ifsc" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Bank IFSC Code *</label>
                <input wire:model="ifsc" type="text" id="ifsc" required maxlength="11"
                       class="mt-1 block w-full uppercase rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                @error('ifsc') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="pt-4 border-t border-zinc-100 dark:border-zinc-800">
                <label for="current_password" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Confirm Account Password *</label>
                <p class="text-xs text-zinc-500 dark:text-zinc-400">Please enter your login password to authorize this bank detail update.</p>
                <input wire:model="current_password" type="password" id="current_password" required placeholder="••••••••"
                       class="mt-1.5 block w-full sm:w-80 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                @error('current_password') <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
            </div>

            <div class="flex justify-end pt-2">
                <button type="submit" data-test="update-bank-details-button" class="rounded-lg bg-zinc-900 px-6 py-2.5 text-sm font-semibold text-white shadow-xs hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100 transition">
                    <span wire:loading.remove wire:target="updateBankDetails">Save & Update Bank Details</span>
                    <span wire:loading wire:target="updateBankDetails">Verifying...</span>
                </button>
            </div>
        </form>
    </div>
</div>

