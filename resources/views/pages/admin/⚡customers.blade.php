<?php

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Mail\CustomerWelcomeMail;
use App\Mail\PasswordChangedByAdminMail;
use App\Models\User;
use App\Services\AdminActivityLogger;
use App\Support\PasswordGenerator;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts.admin')] #[Title('Customers')] class extends Component {
    use PasswordValidationRules, ProfileValidationRules, WithPagination;

    public string $search = '';

    // Create Customer Modal state
    public bool $showCreateModal = false;
    public string $name = '';
    public string $email = '';
    public string $phone = '';
    public string $creationMode = 'link'; // 'link' or 'password'
    public string $password = '';
    public string $password_confirmation = '';
    public ?string $generatedPassword = null;
    public bool $mustChangePassword = true;
    public bool $markEmailVerified = false;

    // Reset Password Modal state
    public bool $showResetPasswordModal = false;
    public ?int $resetCustomerId = null;
    public string $resetTargetEmail = '';
    public string $resetMode = 'link'; // 'link' or 'password'
    public string $newPassword = '';
    public string $newPassword_confirmation = '';
    public ?string $resetGeneratedPassword = null;
    public bool $resetMustChangePassword = true;

    public function mount(): void
    {
        $this->authorize('customers.view');
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function openCreateCustomerModal(): void
    {
        $this->authorize('customers.create');

        $this->reset([
            'name',
            'email',
            'phone',
            'creationMode',
            'password',
            'password_confirmation',
            'generatedPassword',
            'mustChangePassword',
            'markEmailVerified',
        ]);
        $this->resetErrorBag();
        $this->showCreateModal = true;
    }

    public function closeCreateCustomerModal(): void
    {
        $this->showCreateModal = false;
        $this->reset([
            'name',
            'email',
            'phone',
            'creationMode',
            'password',
            'password_confirmation',
            'generatedPassword',
            'mustChangePassword',
            'markEmailVerified',
        ]);
        $this->resetErrorBag();
    }

    public function generateCustomerPassword(): void
    {
        $this->generatedPassword = PasswordGenerator::generate();
        $this->password = $this->generatedPassword;
        $this->password_confirmation = $this->generatedPassword;
    }

    public function saveCustomer(): void
    {
        $this->authorize('customers.create');

        $rules = [
            ...$this->profileRules(),
            'phone' => ['nullable', 'string', 'max:20', Rule::unique(User::class)],
        ];

        if ($this->creationMode === 'password') {
            $rules['password'] = $this->passwordRules();
        }

        $this->validate($rules);

        $initialPassword = $this->creationMode === 'password'
            ? $this->password
            : Str::random(32);

        $customer = (new User)->forceFill([
            'name' => $this->name,
            'email' => $this->email,
            'phone' => filled($this->phone) ? $this->phone : null,
            'password' => Hash::make($initialPassword),
            'must_change_password' => $this->creationMode === 'password' ? $this->mustChangePassword : false,
            'is_active' => true,
        ]);
        $customer->save();

        if ($this->markEmailVerified) {
            $customer->markEmailAsVerified();
        }

        if ($this->creationMode === 'link') {
            // Generate set-password token and send welcome email (24-hour expiry)
            $token = Password::broker('users_welcome')->createToken($customer);
            Mail::to($customer->email)->queue(new CustomerWelcomeMail($customer, $token));
        }

        if (! $this->markEmailVerified && $this->creationMode === 'password') {
            $customer->sendEmailVerificationNotification();
        }

        AdminActivityLogger::log('customer.created', $customer, [
            'name' => $customer->name,
            'email' => $customer->email,
            'phone' => $customer->phone,
            'email_verified' => (bool) $customer->email_verified_at,
            'creation_mode' => $this->creationMode,
            'must_change_password' => $customer->must_change_password,
        ]);

        session()->flash('status', "Customer [{$customer->email}] created successfully.");
        $this->closeCreateCustomerModal();
    }

    public function openResetPasswordModal(int $userId): void
    {
        $this->authorize('customers.password');

        $customer = User::findOrFail($userId);
        $this->resetCustomerId = $customer->id;
        $this->resetTargetEmail = $customer->email;
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
            'resetCustomerId',
            'resetTargetEmail',
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

        if (! $this->resetCustomerId) {
            $this->closeResetPasswordModal();

            return;
        }

        $customer = User::findOrFail($this->resetCustomerId);

        if ($this->resetMode === 'link') {
            $token = Password::broker('users')->createToken($customer);
            $customer->sendPasswordResetNotification($token);

            AdminActivityLogger::log('customer.reset_link_sent', $customer, [
                'email' => $customer->email,
            ]);

            session()->flash('status', "Password reset link sent to [{$customer->email}].");
        } else {
            $this->validate([
                'newPassword' => ['required', 'string', 'confirmed', PasswordRule::defaults()],
            ]);

            $customer->forceFill([
                'password' => Hash::make($this->newPassword),
                'must_change_password' => $this->resetMustChangePassword,
                'remember_token' => null,
            ])->save();

            // Clear sessions for this customer from database sessions table
            DB::table('sessions')->where('user_id', $customer->id)->delete();

            // Notify customer by email
            Mail::to($customer->email)->queue(new PasswordChangedByAdminMail($customer));

            AdminActivityLogger::log('customer.password_reset', $customer, [
                'email' => $customer->email,
                'must_change_password' => $this->resetMustChangePassword,
            ]);

            session()->flash('status', "Password for customer [{$customer->email}] has been reset successfully.");
        }

        $this->closeResetPasswordModal();
    }

    public function toggleActive(int $userId): void
    {
        $this->authorize('customers.manage');

        $customer = User::findOrFail($userId);
        $customer->forceFill(['is_active' => ! $customer->is_active])->save();

        AdminActivityLogger::log($customer->is_active ? 'customer.activated' : 'customer.deactivated', $customer, [
            'user_id' => $customer->id,
            'email' => $customer->email,
            'is_active' => $customer->is_active,
        ]);

        session()->flash('status', "Customer [{$customer->email}] status updated to ".($customer->is_active ? 'active' : 'inactive').'.');
    }

    /**
     * @return LengthAwarePaginator<User>
     */
    #[Computed]
    public function customers(): LengthAwarePaginator
    {
        return User::query()
            ->when(filled($this->search), function ($query) {
                $query->where(function ($q) {
                    $q->where('name', 'like', '%'.$this->search.'%')
                        ->orWhere('email', 'like', '%'.$this->search.'%')
                        ->orWhere('phone', 'like', '%'.$this->search.'%');
                });
            })
            ->latest('id')
            ->paginate(10);
    }
}; ?>

<div class="space-y-6">
    <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-white">Customers</h2>
            <p class="text-sm text-zinc-500 dark:text-zinc-400">Manage customer accounts, create new customers, and reset credentials.</p>
        </div>

        <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
            <div class="w-full sm:w-72">
                <div class="relative">
                    <input wire:model.live.debounce.300ms="search"
                           type="text"
                           placeholder="Search name, email, or phone..."
                           class="w-full rounded-lg border border-zinc-300 bg-white py-2 pl-9 pr-3 text-sm text-zinc-900 placeholder-zinc-400 focus:border-zinc-500 focus:outline-hidden focus:ring-1 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-900 dark:text-white dark:placeholder-zinc-500 dark:focus:border-zinc-400 dark:focus:ring-zinc-400" />
                    <svg class="pointer-events-none absolute left-3 top-2.5 size-4 text-zinc-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
                    </svg>
                </div>
            </div>

            @can('customers.create')
                <button wire:click="openCreateCustomerModal"
                        type="button"
                        class="inline-flex items-center justify-center gap-1.5 rounded-lg bg-zinc-900 px-4 py-2 text-xs font-semibold text-white shadow-xs hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100">
                    <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/>
                    </svg>
                    <span>Add Customer</span>
                </button>
            @endcan
        </div>
    </div>

    @if (session('status'))
        <div class="rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800 dark:border-green-900/50 dark:bg-green-950/30 dark:text-green-300">
            {{ session('status') }}
        </div>
    @endif

    <div class="overflow-hidden rounded-xl border border-zinc-200 bg-white shadow-xs dark:border-zinc-800 dark:bg-zinc-900">
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm text-zinc-600 dark:text-zinc-300">
                <thead class="border-b border-zinc-200 bg-zinc-50 text-xs font-semibold uppercase text-zinc-500 dark:border-zinc-800 dark:bg-zinc-800/50 dark:text-zinc-400">
                    <tr>
                        <th class="px-6 py-3.5">Customer</th>
                        <th class="px-6 py-3.5">Status</th>
                        <th class="px-6 py-3.5">Joined</th>
                        <th class="px-6 py-3.5 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-800">
                    @forelse ($this->customers as $customer)
                        <tr class="transition hover:bg-zinc-50/50 dark:hover:bg-zinc-800/30">
                            <td class="px-6 py-4">
                                <div class="flex items-center gap-3">
                                    <div class="flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-200 font-semibold text-xs text-zinc-700 dark:bg-zinc-700 dark:text-zinc-200">
                                        {{ $customer->initials() }}
                                    </div>
                                    <div>
                                        <div class="font-medium text-zinc-900 dark:text-white">{{ $customer->name }}</div>
                                        <div class="text-xs text-zinc-500 dark:text-zinc-400 flex items-center gap-2">
                                            <span>{{ $customer->email }}</span>
                                            @if ($customer->phone)
                                                <span class="text-zinc-400">· {{ $customer->phone }}</span>
                                            @endif
                                            @if ($customer->must_change_password)
                                                <span class="inline-flex items-center rounded bg-amber-50 px-1.5 py-0.2 text-[10px] font-medium text-amber-700 dark:bg-amber-950/40 dark:text-amber-400">
                                                    Password change pending
                                                </span>
                                            @endif
                                        </div>
                                    </div>
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                @if ($customer->is_active)
                                    <span class="inline-flex items-center rounded-full bg-green-50 px-2.5 py-0.5 text-xs font-medium text-green-700 dark:bg-green-950/50 dark:text-green-400 border border-green-200 dark:border-green-800">
                                        Active
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full bg-zinc-100 px-2.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400 border border-zinc-300 dark:border-zinc-700">
                                        Inactive
                                    </span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-xs text-zinc-500 dark:text-zinc-400">
                                @date($customer->created_at, 'M j, Y')
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex items-center justify-end gap-2">
                                    <a href="{{ route('admin.customers.show', $customer) }}"
                                       wire:navigate
                                       class="rounded-md border border-zinc-300 px-2.5 py-1 text-xs font-medium text-zinc-700 hover:bg-zinc-100 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800 transition">
                                        View
                                    </a>
                                    @can('customers.password')
                                        <button wire:click="openResetPasswordModal({{ $customer->id }})"
                                                type="button"
                                                class="rounded-md border border-amber-300 px-2.5 py-1 text-xs font-medium text-amber-700 hover:bg-amber-50 dark:border-amber-700 dark:text-amber-300 dark:hover:bg-amber-950/30 transition">
                                            Reset Password
                                        </button>
                                    @endcan
                                    @can('customers.manage')
                                        <button wire:click="toggleActive({{ $customer->id }})"
                                                type="button"
                                                class="rounded-md px-2.5 py-1 text-xs font-medium transition {{ $customer->is_active ? 'border border-red-200 text-red-600 hover:bg-red-50 dark:border-red-900/50 dark:text-red-400 dark:hover:bg-red-950/30' : 'border border-green-200 text-green-600 hover:bg-green-50 dark:border-green-900/50 dark:text-green-400 dark:hover:bg-green-950/30' }}">
                                            {{ $customer->is_active ? 'Deactivate' : 'Activate' }}
                                        </button>
                                    @endcan
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="4" class="px-6 py-10 text-center text-sm text-zinc-500 dark:text-zinc-400">
                                No customers found.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($this->customers->hasPages())
            <div class="border-t border-zinc-200 p-4 dark:border-zinc-800">
                {{ $this->customers->links() }}
            </div>
        @endif
    </div>

    <!-- Create Customer Modal -->
    @if ($showCreateModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div wire:click="closeCreateCustomerModal" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-lg rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900 space-y-5">
                <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
                    <h3 class="text-base font-bold text-zinc-900 dark:text-white">Create New Customer</h3>
                    <button wire:click="closeCreateCustomerModal" type="button" class="text-zinc-400 hover:text-zinc-600 dark:hover:text-zinc-200">
                        ✕
                    </button>
                </div>

                <form wire:submit="saveCustomer" class="space-y-4">
                    <div>
                        <label for="name" class="block text-xs font-semibold text-zinc-900 dark:text-white">Full Name *</label>
                        <input wire:model="name" id="name" type="text" required placeholder="John Doe"
                               class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('name')
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="email" class="block text-xs font-semibold text-zinc-900 dark:text-white">Email Address *</label>
                        <input wire:model="email" id="email" type="email" required placeholder="john@example.com"
                               class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('email')
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div>
                        <label for="phone" class="block text-xs font-semibold text-zinc-900 dark:text-white">Phone Number (Optional)</label>
                        <input wire:model="phone" id="phone" type="text" placeholder="9876543210"
                               class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                        @error('phone')
                            <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="space-y-2">
                        <label class="block text-xs font-semibold text-zinc-900 dark:text-white">Password Setup</label>
                        <div class="grid grid-cols-2 gap-3">
                            <label class="flex items-center gap-2 p-2.5 rounded-lg border border-zinc-200 dark:border-zinc-800 cursor-pointer {{ $creationMode === 'link' ? 'bg-zinc-100 dark:bg-zinc-800 border-zinc-400' : '' }}">
                                <input type="radio" wire:model.live="creationMode" value="link" class="size-3.5 text-zinc-900">
                                <span class="text-xs font-medium text-zinc-900 dark:text-white">Send set-password link</span>
                            </label>
                            <label class="flex items-center gap-2 p-2.5 rounded-lg border border-zinc-200 dark:border-zinc-800 cursor-pointer {{ $creationMode === 'password' ? 'bg-zinc-100 dark:bg-zinc-800 border-zinc-400' : '' }}">
                                <input type="radio" wire:model.live="creationMode" value="password" class="size-3.5 text-zinc-900">
                                <span class="text-xs font-medium text-zinc-900 dark:text-white">Set password now</span>
                            </label>
                        </div>
                    </div>

                    @if ($creationMode === 'password')
                        <div class="space-y-3 rounded-xl border border-zinc-200 bg-zinc-50/50 p-4 dark:border-zinc-800 dark:bg-zinc-800/40">
                            <div class="flex items-center justify-between">
                                <span class="text-xs font-semibold text-zinc-900 dark:text-white">Account Password</span>
                                <button wire:click="generateCustomerPassword" type="button" class="text-xs font-medium text-indigo-600 dark:text-indigo-400 hover:underline">
                                    ⚡ Generate strong password
                                </button>
                            </div>

                            @if ($generatedPassword)
                                <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 text-xs dark:border-amber-900/50 dark:bg-amber-950/40">
                                    <div class="font-semibold text-amber-900 dark:text-amber-200">Generated Password (shown once):</div>
                                    <div class="mt-1.5 flex items-center justify-between gap-2">
                                        <code class="rounded bg-white/80 px-2 py-1 font-mono text-xs text-zinc-900 dark:bg-zinc-900 dark:text-white select-all">{{ $generatedPassword }}</code>
                                        <button type="button"
                                                onclick="navigator.clipboard.writeText('{{ $generatedPassword }}'); alert('Password copied to clipboard!');"
                                                class="rounded bg-amber-200 px-2 py-1 text-[11px] font-semibold text-amber-900 hover:bg-amber-300 dark:bg-amber-800 dark:text-amber-100">
                                            Copy
                                        </button>
                                    </div>
                                    <p class="mt-1 text-[10px] text-amber-700 dark:text-amber-400">Copy this password now. It will not be shown again after closing.</p>
                                </div>
                            @endif

                            <div>
                                <label for="password" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Password</label>
                                <input wire:model="password" id="password" type="password" required
                                       class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                                @error('password')
                                    <p class="mt-1 text-xs text-red-600 dark:text-red-400">{{ $message }}</p>
                                @enderror
                            </div>

                            <div>
                                <label for="password_confirmation" class="block text-xs font-medium text-zinc-700 dark:text-zinc-300">Confirm Password</label>
                                <input wire:model="password_confirmation" id="password_confirmation" type="password" required
                                       class="mt-1 block w-full rounded-lg border border-zinc-300 px-3 py-2 text-xs text-zinc-900 focus:border-zinc-500 focus:outline-hidden dark:border-zinc-700 dark:bg-zinc-800 dark:text-white" />
                            </div>

                            <label class="flex items-center gap-2 pt-1 text-xs cursor-pointer">
                                <input type="checkbox" wire:model="mustChangePassword" class="size-3.5 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800" />
                                <span class="text-zinc-700 dark:text-zinc-300 font-medium">Require password change on first login</span>
                            </label>
                        </div>
                    @endif

                    <label class="flex items-center gap-2 pt-1 text-xs cursor-pointer">
                        <input type="checkbox" wire:model="markEmailVerified" class="size-3.5 rounded border-zinc-300 text-zinc-900 focus:ring-zinc-500 dark:border-zinc-700 dark:bg-zinc-800" />
                        <span class="text-zinc-700 dark:text-zinc-300 font-medium">Mark email as verified immediately</span>
                    </label>

                    <div class="flex items-center justify-end gap-3 pt-3 border-t border-zinc-200 dark:border-zinc-800">
                        <button wire:click="closeCreateCustomerModal" type="button" class="rounded-lg border border-zinc-300 px-4 py-2 text-xs font-semibold text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                            Cancel
                        </button>
                        <button type="submit" class="rounded-lg bg-zinc-900 px-5 py-2 text-xs font-semibold text-white hover:bg-zinc-800 dark:bg-white dark:text-zinc-900 dark:hover:bg-zinc-100">
                            Create Customer
                        </button>
                    </div>
                </form>
            </div>
        </div>
    @endif

    <!-- Reset Customer Password Modal -->
    @if ($showResetPasswordModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div wire:click="closeResetPasswordModal" class="fixed inset-0 bg-zinc-950/60 backdrop-blur-xs"></div>

            <div class="relative w-full max-w-md rounded-2xl border border-zinc-200 bg-white p-6 shadow-xl dark:border-zinc-800 dark:bg-zinc-900 space-y-5">
                <div class="flex items-center justify-between border-b border-zinc-200 dark:border-zinc-800 pb-3">
                    <div>
                        <h3 class="text-base font-bold text-zinc-900 dark:text-white">Reset Customer Password</h3>
                        <p class="text-xs text-zinc-500 dark:text-zinc-400 font-mono">{{ $resetTargetEmail }}</p>
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
                            A secure password reset link will be sent to <strong>{{ $resetTargetEmail }}</strong>.
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
