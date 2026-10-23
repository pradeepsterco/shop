<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts.auth')] #[Title('Change Password')] class extends Component {
    public string $password = '';
    public string $password_confirmation = '';

    public function mount(): void
    {
        /** @var User|null $user */
        $user = Auth::guard('web')->user();

        if (! $user || ! $user->must_change_password) {
            $this->redirect(route('dashboard'), navigate: true);
        }
    }

    public function changePassword(): void
    {
        /** @var User $user */
        $user = Auth::guard('web')->user();

        $this->validate([
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        if (Hash::check($this->password, $user->password)) {
            $this->addError('password', 'Your new password must be different from your current password.');

            return;
        }

        $user->forceFill([
            'password' => Hash::make($this->password),
            'must_change_password' => false,
        ])->save();

        // Refresh the password hash stored in the session for AuthenticateSession
        if (request()->hasSession()) {
            request()->session()->put('password_hash_web', $user->getAuthPassword());
        }

        $this->reset(['password', 'password_confirmation']);

        session()->flash('status', 'Your password has been changed successfully.');

        if ($user->isSeller()) {
            $this->redirect(route('seller.dashboard'), navigate: true);

            return;
        }

        $this->redirect(route('dashboard'), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        title="Password change required"
        description="An administrator has requested that you set a new password before continuing."
    />

    @if (session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-xs text-green-800 dark:border-green-900/50 dark:bg-green-950/40 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    <form wire:submit="changePassword" class="flex flex-col gap-6">
        <div class="space-y-4">
            <div>
                <label for="password" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">New password</label>
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
                <label for="password_confirmation" class="block text-sm font-medium text-zinc-700 dark:text-zinc-300">Confirm new password</label>
                <input wire:model="password_confirmation"
                       type="password"
                       id="password_confirmation"
                       autocomplete="new-password"
                       required
                       class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800 dark:text-white dark:placeholder-zinc-500" />
            </div>
        </div>

        <button type="submit"
                class="w-full rounded-lg bg-zinc-900 py-2.5 text-center text-sm font-semibold text-white transition hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100">
            Update password
        </button>
    </form>

    <div class="text-center">
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="text-xs text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200">
                Log out
            </button>
        </form>
    </div>
</div>
