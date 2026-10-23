<?php

use App\Models\Admin;
use App\Services\AdminActivityLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.admin-auth')] #[Title('Set Admin Password')] class extends Component {
    #[Url]
    public string $token = '';

    #[Url]
    public string $email = '';

    public string $password = '';
    public string $password_confirmation = '';
    public bool $isTokenValid = true;

    public function mount(): void
    {
        $admin = Admin::where('email', $this->email)->first();

        if (! $admin || ! Password::broker('admins')->tokenExists($admin, $this->token)) {
            $this->isTokenValid = false;
        }
    }

    public function setPassword(): void
    {
        $admin = Admin::where('email', $this->email)->first();

        if (! $admin || ! Password::broker('admins')->tokenExists($admin, $this->token)) {
            $this->isTokenValid = false;
            $this->addError('token', 'This invitation or password reset link has expired or is invalid.');

            return;
        }

        $this->validate([
            'password' => ['required', 'string', 'confirmed', PasswordRule::min(8)],
        ]);

        // Never change is_active here - only set password and invitation_accepted_at
        $admin->update([
            'password' => Hash::make($this->password),
            'invitation_accepted_at' => now(),
        ]);

        // Consume and delete the token
        Password::broker('admins')->deleteToken($admin);

        AdminActivityLogger::log('staff.password_set', $admin, [
            'email' => $admin->email,
        ], $admin);

        if (! $admin->is_active) {
            session()->flash('error', 'Your password was set, but this account is currently deactivated. Please contact a Super Administrator.');
            $this->redirect(route('admin.login'), navigate: true);

            return;
        }

        Auth::guard('admin')->login($admin);
        session()->flash('status', 'Welcome to the team! Your password has been set successfully.');
        $this->redirect(route('admin.dashboard'), navigate: true);
    }
}; ?>

<div class="rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    <div class="mb-6 text-center">
        <div class="mx-auto flex size-12 items-center justify-center rounded-xl bg-zinc-900 text-white dark:bg-white dark:text-zinc-900">
            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>
            </svg>
        </div>
        <h1 class="mt-4 text-xl font-bold tracking-tight text-zinc-900 dark:text-white">
            Set Your Admin Password
        </h1>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            @if ($isTokenValid)
                Account: <span class="font-semibold text-zinc-700 dark:text-zinc-300">{{ $email }}</span>
            @else
                Invalid or Expired Invitation Link
            @endif
        </p>
    </div>

    @if (! $isTokenValid)
        <div class="space-y-4">
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-xs text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
                This invitation or password reset link is invalid or has expired. Please contact a Super Administrator to resend an invitation.
            </div>
            <div class="text-center pt-2">
                <a href="{{ route('admin.login') }}" wire:navigate class="text-xs font-semibold text-zinc-900 hover:underline dark:text-white">
                    ← Return to Login
                </a>
            </div>
        </div>
    @else
        <form wire:submit="setPassword" class="space-y-4">
            <div>
                <label for="password" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">New Password</label>
                <input wire:model="password" id="password" type="password" required autocomplete="new-password"
                       class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 shadow-xs focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                @error('password')
                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password_confirmation" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Confirm Password</label>
                <input wire:model="password_confirmation" id="password_confirmation" type="password" required autocomplete="new-password"
                       class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 shadow-xs focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
            </div>

            <button type="submit"
                    class="w-full rounded-lg bg-zinc-900 py-2.5 text-xs font-semibold text-white shadow-xs transition hover:bg-zinc-800 focus:outline-hidden focus:ring-2 focus:ring-zinc-500 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100">
                Set Password & Log In
            </button>
        </form>
    @endif
</div>

