<?php

use App\Models\Admin;
use App\Services\AdminActivityLogger;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.admin-auth')] #[Title('Change Admin Password')] class extends Component {
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(): void
    {
        /** @var Admin|null $admin */
        $admin = Auth::guard('admin')->user();

        if (! $admin || ! $admin->must_change_password) {
            $this->redirect(route('admin.dashboard'), navigate: true);
        }
    }

    public function changePassword(): void
    {
        /** @var Admin $admin */
        $admin = Auth::guard('admin')->user();

        $this->validate([
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if (Hash::check($this->password, $admin->password)) {
            $this->addError('password', 'Your new password must be different from your current password.');

            return;
        }

        $admin->forceFill([
            'password' => Hash::make($this->password),
            'must_change_password' => false,
        ])->save();

        if (request()->hasSession()) {
            request()->session()->put('password_hash_admin', $admin->getAuthPassword());
        }

        AdminActivityLogger::log('staff.password_changed_forced', $admin, [
            'email' => $admin->email,
        ], $admin);

        $this->reset(['password', 'password_confirmation']);

        session()->flash('status', 'Your password has been changed successfully.');
        $this->redirect(route('admin.dashboard'), navigate: true);
    }
}; ?>

<div class="rounded-2xl border border-zinc-200 bg-white p-8 shadow-sm dark:border-zinc-800 dark:bg-zinc-900">
    <div class="mb-6 text-center">
        <div class="mx-auto flex size-12 items-center justify-center rounded-xl bg-amber-500 text-white">
            <svg class="size-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/>
            </svg>
        </div>
        <h1 class="mt-4 text-xl font-bold tracking-tight text-zinc-900 dark:text-white">
            Password Change Required
        </h1>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            For security reasons, an administrator has requested that you set a new password before proceeding.
        </p>
    </div>

    @if (session('status'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 p-4 text-xs text-green-800 dark:border-green-900/50 dark:bg-green-950/40 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="changePassword" class="space-y-4">
        <div>
            <label for="password" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">New Password</label>
            <input wire:model="password"
                   type="password"
                   id="password"
                   autocomplete="new-password"
                   required
                   class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500" />
            @error('password')
                <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Confirm New Password</label>
            <input wire:model="password_confirmation"
                   type="password"
                   id="password_confirmation"
                   autocomplete="new-password"
                   required
                   class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500" />
        </div>

        <button type="submit"
                class="w-full rounded-lg bg-zinc-900 py-2.5 text-center text-sm font-semibold text-white transition hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100">
            Update Password & Continue
        </button>
    </form>

    <div class="mt-6 border-t border-zinc-100 pt-4 text-center dark:border-zinc-800">
        <form method="POST" action="{{ route('admin.logout') }}">
            @csrf
            <button type="submit" class="text-xs text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200">
                Log out
            </button>
        </form>
    </div>
</div>
