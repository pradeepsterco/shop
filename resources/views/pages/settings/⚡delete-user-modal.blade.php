<?php

use App\Concerns\PasswordValidationRules;
use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

new class extends Component {
    use PasswordValidationRules;

    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => $this->currentPasswordRules(),
        ]);

        /** @var \App\Models\User $user */
        $user = Auth::user();

        if ($user->hasOpenOrders() || ($user->sellerProfile && $user->sellerProfile->hasOpenOrders())) {
            $this->addError('password', 'Cannot delete your account while you have active or undelivered orders (as customer or seller).');

            return;
        }

        // Returns and refunds in progress need the account (to collect the item and pay the customer back)
        if (app(\App\Returns\Contracts\ReturnRequestServiceInterface::class)->hasOpenReturns($user->id)
            || app(\App\Orders\Contracts\CustomerRefundServiceInterface::class)->hasOpenRefunds($user->id)) {
            $this->addError('password', 'You can delete your account once your open returns are finished and any refund owed to you has been paid. We are working on them now.');

            return;
        }

        try {
            if ($user->hasAnyOrders()) {
                $user->anonymize();
                $logout();
            } else {
                tap($user, $logout(...))->delete();
            }
        } catch (\DomainException $e) {
            $this->addError('password', $e->getMessage());

            return;
        }

        $this->redirect('/', navigate: true);
    }
}; ?>

<flux:modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable class="max-w-lg">
    <form method="POST" wire:submit="deleteUser" class="space-y-6">
        <div>
            <flux:heading size="lg">{{ __('Are you sure you want to delete your account?') }}</flux:heading>

            <flux:subheading>
                {{ __('Once your account is deleted, all of its resources and data will be permanently deleted. Please enter your password to confirm you would like to permanently delete your account.') }}
            </flux:subheading>
        </div>

        <flux:input wire:model="password" :label="__('Password')" type="password" viewable />

        <div class="flex justify-end space-x-2 rtl:space-x-reverse">
            <flux:modal.close>
                <flux:button variant="filled">{{ __('Cancel') }}</flux:button>
            </flux:modal.close>

            <flux:button variant="danger" type="submit" data-test="confirm-delete-user-button">
                {{ __('Delete account') }}
            </flux:button>
        </div>
    </form>
</flux:modal>
