<?php

use App\Mail\PasswordChangedByAdminMail;
use App\Models\User;
use App\Services\AdminActivityLogger;
use App\Support\PasswordGenerator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin')] #[Title('Customer Details')] class extends Component {
    public User $user;

    // Reset Password Modal state
    public bool $showResetPasswordModal = false;
    public string $resetMode = 'link'; // 'link' or 'password'
    public string $newPassword = '';
    public string $newPassword_confirmation = '';
    public ?string $resetGeneratedPassword = null;
    public bool $resetMustChangePassword = true;

    public function mount(User $user): void
    {
        $this->authorize('customers.view');
        $this->user = $user;
    }

    public function openResetPasswordModal(): void
    {
        $this->authorize('customers.password');

        $this->resetMode = 'link';
        $this->resetMustChangePassword = true;
        $this->reset([
            'newPassword',
            'newPassword_confirmation',
            'resetGeneratedPassword',
        ]);
        $this->resetErrorBag();
        $this->showResetPasswordModal = true;
    }

    public function closeResetPasswordModal(): void
    {
        $this->showResetPasswordModal = false;
        $this->reset([
            'resetMode',
            'newPassword',
            'newPassword_confirmation',
            'resetGeneratedPassword',
            'resetMustChangePassword',
        ]);
        $this->resetErrorBag();
    }

    public function generateResetPassword(): void
    {
        $this->resetGeneratedPassword = PasswordGenerator::generate();
        $this->newPassword = $this->resetGeneratedPassword;
        $this->newPassword_confirmation = $this->resetGeneratedPassword;
    }

    public function confirmResetPassword(): void
    {
        $this->authorize('customers.password');

        if ($this->resetMode === 'link') {
            $token = Password::broker('users')->createToken($this->user);
            $this->user->sendPasswordResetNotification($token);

            AdminActivityLogger::log('customer.reset_link_sent', $this->user, [
                'email' => $this->user->email,
            ]);

            session()->flash('status', "Password reset link sent to [{$this->user->email}].");
        } else {
            $this->validate([
                'newPassword' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
            ]);

            $this->user->forceFill([
                'password' => Hash::make($this->newPassword),
                'must_change_password' => $this->resetMustChangePassword,
                'remember_token' => null,
            ])->save();

            // Clear sessions for this customer from database sessions table
            DB::table('sessions')->where('user_id', $this->user->id)->delete();

            // Notify customer by email
            Mail::to($this->user->email)->queue(new PasswordChangedByAdminMail($this->user));

            AdminActivityLogger::log('customer.password_reset', $this->user, [
                'email' => $this->user->email,
                'must_change_password' => $this->resetMustChangePassword,
            ]);

            session()->flash('status', "Password for customer [{$this->user->email}] has been reset successfully.");
        }

        $this->closeResetPasswordModal();
    }

    public function toggleActive(): void
    {
        $this->authorize('customers.manage');

        $this->user->forceFill(['is_active' => ! $this->user->is_active])->save();

        AdminActivityLogger::log($this->user->is_active ? 'customer.activated' : 'customer.deactivated', $this->user, [
            'user_id' => $this->user->id,
            'email' => $this->user->email,
            'is_active' => $this->user->is_active,
        ]);

        session()->flash('status', 'Customer status updated to '.($this->user->is_active ? 'active' : 'inactive').'.');
    }
}; ?>

<div class="space-y-6">
    <div class="flex items-center gap-2">
        <a href="{{ route('admin.customers') }}" wire:navigate class="inline-flex items-center gap-1.5 text-sm font-medium text-zinc-500 hover:text-zinc-900 dark:text-zinc-400 dark:hover:text-white transition">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M10 19l-7-7m0 0l7-7m-7 7h18"/>
            </svg>
            <span>Back to Customers</span>
        </a>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-900/50 dark:bg-green-950/30 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="flex flex-col gap-4 border-b border-zinc-200 p-6 sm:flex-row sm:items-center sm:justify-between dark:border-zinc-800">
            <div class="flex items-center gap-4">
                <div class="flex size-14 items-center justify-center rounded-full bg-zinc-200 text-lg font-bold text-zinc-800 dark:bg-zinc-700 dark:text-zinc-100">
                    {{ $user->initials() }}
                </div>
                <div>
                    <h2 class="text-xl font-bold tracking-tight text-zinc-900 dark:text-white" data-test="customer-name">{{ $user->name }}</h2>
                    <p class="text-sm text-zinc-500 dark:text-zinc-400" data-test="customer-email">{{ $user->email }}</p>
                </div>
            </div>

            <div class="flex items-center gap-3">
                @if ($user->is_active)
                    <span class="inline-flex items-center rounded-full bg-green-50 px-3 py-1 text-xs font-medium text-green-700 dark:bg-green-950/50 dark:text-green-400 border border-green-200 dark:border-green-800">
                        Active Account
                    </span>
                @else
                    <span class="inline-flex items-center rounded-full bg-zinc-100 px-3 py-1 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 border border-zinc-300 dark:border-zinc-700">
                        Inactive Account
                    </span>
                @endif

                @can('customers.password')
                    <button wire:click="openResetPasswordModal"
                            type="button"
                            class="rounded-lg border border-amber-300 px-3.5 py-2 text-xs font-semibold text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300 dark:hover:bg-amber-950/30 shadow-xs transition">
                        Reset Password
                    </button>
                @endcan

                @can('customers.manage')
                    <button wire:click="toggleActive"
                            type="button"
                            class="rounded-lg px-3.5 py-2 text-xs font-semibold shadow-xs transition {{ $user->is_active ? 'bg-red-600 text-white hover:bg-red-700 dark:bg-red-500 dark:hover:bg-red-600' : 'bg-green-600 text-white hover:bg-green-700 dark:bg-green-500 dark:hover:bg-green-600' }}">
                        {{ $user->is_active ? 'Deactivate Customer' : 'Activate Customer' }}
                    </button>
                @endcan
            </div>
        </div>

        <div class="p-6">
            <h3 class="text-sm font-semibold uppercase tracking-wider text-zinc-500 dark:text-zinc-400">Account Information</h3>
            <dl class="mt-4 grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-3">
                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Customer ID</dt>
                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">#{{ $user->id }}</dd>
                </div>

                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Full Name</dt>
                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $user->name }}</dd>
                </div>

                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Email Address</dt>
                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $user->email }}</dd>
                </div>

                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Phone Number</dt>
                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">{{ $user->phone ?? '—' }}</dd>
                </div>

                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Password Change Pending</dt>
                    <dd class="mt-1 text-sm font-semibold {{ $user->must_change_password ? 'text-amber-600 dark:text-amber-400' : 'text-zinc-900 dark:text-white' }}">
                        {{ $user->must_change_password ? 'Yes (Forced on next login)' : 'No' }}
                    </dd>
                </div>

                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Email Verified At</dt>
                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">
                        {{ $user->email_verified_at?->format('M j, Y H:i:s') ?? 'Not verified' }}
                    </dd>
                </div>

                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Registered On</dt>
                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">
                        {{ $user->created_at?->format('M j, Y H:i:s') ?? '—' }}
                    </dd>
                </div>

                <div class="rounded-lg border border-zinc-100 bg-zinc-50/50 p-4 dark:border-zinc-800/80 dark:bg-zinc-800/30">
                    <dt class="text-xs font-medium text-zinc-500 dark:text-zinc-400">Last Updated</dt>
                    <dd class="mt-1 text-sm font-semibold text-zinc-900 dark:text-white">
                        {{ $user->updated_at?->format('M j, Y H:i:s') ?? '—' }}
                    </dd>
                </div>
            </dl>
        </div>
    </div>

    <!-- Reset Customer Password Modal -->
    @if ($showResetPasswordModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div wire:click="closeResetPasswordModal" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900 space-y-5">
                <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
                    <div>
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white">Reset Customer Password</h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 font-mono">{{ $user->email }}</p>
                    </div>
                    <button wire:click="closeResetPasswordModal" type="button" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        ✕
                    </button>
                </div>

                <form wire:submit="confirmResetPassword" class="space-y-4">
                    <div class="grid grid-cols-2 gap-3">
                        <label class="flex items-center gap-2 p-2.5 rounded-lg border border-zinc-200 dark:border-zinc-800 cursor-pointer {{ $resetMode === 'link' ? 'bg-zinc-100 dark:bg-zinc-800 border-zinc-400' : '' }}">
                            <input type="radio" wire:model.live="resetMode" value="link" class="size-3.5 text-zinc-900">
                            <span class="text-xs font-medium text-zinc-900 dark:text-white">Send reset link</span>
                        </label>
                        <label class="flex items-center gap-2 p-2.5 rounded-lg border border-zinc-200 dark:border-zinc-800 cursor-pointer {{ $resetMode === 'password' ? 'bg-zinc-100 dark:bg-zinc-800 border-zinc-400' : '' }}">
                            <input type="radio" wire:model.live="resetMode" value="password" class="size-3.5 text-zinc-900">
                            <span class="text-xs font-medium text-zinc-900 dark:text-white">Set new password</span>
                        </label>
                    </div>

                    @if ($resetMode === 'password')
                        <div class="space-y-3 rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-800/40">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-zinc-900 dark:text-white">New Password</span>
                                <button wire:click="generateResetPassword" type="button" class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                                    ⚡ Generate strong password
                                </button>
                            </div>

                            @if ($resetGeneratedPassword)
                                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs dark:border-amber-900/50 dark:bg-amber-950/40">
                                    <div class="font-semibold text-amber-900 dark:text-amber-200">Generated Password (shown once):</div>
                                    <div class="mt-1.5 flex items-center justify-between gap-2">
                                        <code class="rounded bg-white/80 px-2 py-1 font-mono text-xs text-zinc-900 dark:bg-zinc-900 dark:text-white select-all">{{ $resetGeneratedPassword }}</code>
                                        <button type="button"
                                                onclick="navigator.clipboard.writeText('{{ $resetGeneratedPassword }}'); alert('Password copied to clipboard!');"
                                                class="rounded bg-amber-200 px-2 py-1 text-[11px] font-semibold text-amber-900 hover:bg-amber-300 dark:bg-amber-800 dark:text-amber-100">
                                            Copy
                                        </button>
                                    </div>
                                    <p class="mt-1 text-[10px] text-amber-700 dark:text-amber-400">Copy this password now. It will not be shown again after closing.</p>
                                </div>
                            @endif

                            <div>
                                <label for="newPassword" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Password</label>
                                <input wire:model="newPassword" id="newPassword" type="password" required
                                       class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                @error('newPassword')
                                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="newPassword_confirmation" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Confirm Password</label>
                                <input wire:model="newPassword_confirmation" id="newPassword_confirmation" type="password" required
                                       class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            </div>

                            <label class="flex items-center gap-2 pt-1 text-xs cursor-pointer">
                                <input type="checkbox" wire:model="resetMustChangePassword" class="size-3.5 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800" />
                                <span class="text-zinc-700 dark:text-zinc-300 font-medium">Require password change on next login</span>
                            </label>
                        </div>
                    @else
                        <p class="text-xs text-zinc-600 dark:text-zinc-400">
                            A secure password reset link will be sent to <strong>{{ $user->email }}</strong>.
                        </p>
                    @endif

                    <div class="flex items-center justify-end gap-3 pt-3 border-t border-zinc-200 dark:border-zinc-800">
                        <button wire:click="closeResetPasswordModal" type="button" class="rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                            Cancel
                        </button>
                        <button type="submit" class="rounded-lg bg-zinc-900 px-5 py-2 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100">
                            {{ $resetMode === 'link' ? 'Send Reset Link' : 'Save New Password' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
