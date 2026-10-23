<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts.auth')] #[Title('Welcome - Set Your Password')] class extends Component {
    #[Url]
    public string $token = '';

    #[Url]
    public string $email = '';

    public string $password = '';
    public string $password_confirmation = '';
    public bool $isTokenValid = true;

    public function mount(): void
    {
        /** @var User|null $user */
        $user = User::where('email', $this->email)->first();

        if (! $user || ! Password::broker('users_welcome')->tokenExists($user, $this->token)) {
            $this->isTokenValid = false;
        }
    }

    public function setPassword(): void
    {
        /** @var User|null $user */
        $user = User::where('email', $this->email)->first();

        if (! $user || ! Password::broker('users_welcome')->tokenExists($user, $this->token)) {
            $this->isTokenValid = false;
            $this->addError('token', 'This welcome link has expired or is invalid.');

            return;
        }

        $this->validate([
            'password' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
        ]);

        $user->forceFill([
            'password' => Hash::make($this->password),
            'must_change_password' => false,
            'email_verified_at' => $user->email_verified_at ?? now(),
        ])->save();

        Password::broker('users_welcome')->deleteToken($user);

        if (! $user->is_active) {
            session()->flash('error', 'Your password was set, but this account is currently deactivated. Please contact support.');
            $this->redirect(route('login'), navigate: true);

            return;
        }

        Auth::guard('web')->login($user);

        if (request()->hasSession()) {
            request()->session()->put('password_hash_web', $user->getAuthPassword());
        }

        session()->flash('status', 'Welcome to '.config('app.name', 'Khilauna').'! Your password has been set successfully.');
        $this->redirect(route('dashboard'), navigate: true);
    }
}; ?>

<div class="flex flex-col gap-6">
    <x-auth-header
        title="Set Your Password"
        :description="$isTokenValid ? 'Welcome to '.config('app.name', 'Khilauna').'! Please choose a password for your new account.' : 'This welcome link is invalid or has expired.'"
    />

    @if (! $isTokenValid)
        <div class="space-y-4">
            <div class="rounded-lg border border-red-200 bg-red-50 p-4 text-xs text-red-700 dark:border-red-900/50 dark:bg-red-950/40 dark:text-red-300">
                This welcome link has expired or is invalid. Please contact support or request a password reset from the login page.
            </div>
            <div class="text-center pt-2">
                <a href="{{ route('login') }}" wire:navigate class="text-xs font-semibold text-zinc-900 hover:underline dark:text-white">
                    ← Return to Login
                </a>
            </div>
        </div>
    @else
        <form wire:submit="setPassword" class="flex flex-col gap-6">
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
                Set Password & Log In
            </button>
        </form>
    @endif
</div>

