<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\CartService;
use Illuminate\Auth\Events\Login;

class MergeGuestCartOnLogin
{
    public function __construct(
        protected CartService $cartService,
    ) {}

    /**
     * Handle the event.
     * Only merge guest carts for the web guard with a User model; ignore admin logins.
     */
    public function handle(Login $event): void
    {
        if ($event->guard !== 'web' || ! ($event->user instanceof User)) {
            return;
        }

        $this->cartService->mergeGuestIntoUser($event->user);
    }
}
