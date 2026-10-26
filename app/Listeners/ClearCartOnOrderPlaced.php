<?php

namespace App\Listeners;

use App\Models\User;
use App\Orders\Events\OrderPlaced;

class ClearCartOnOrderPlaced
{
    /**
     * Handle the event.
     */
    public function handle(OrderPlaced $event): void
    {
        // Only remove cart lines when the order source was 'cart'
        if ($event->source !== 'cart') {
            return;
        }

        $user = User::with('cart')->find($event->userId);
        if (! $user || ! $user->cart) {
            return;
        }

        $listingIds = array_map(fn ($line) => (int) $line['listing_id'], $event->lines);

        $user->cart->items()->whereIn('listing_id', $listingIds)->delete();
        $user->cart->touch();
    }
}
