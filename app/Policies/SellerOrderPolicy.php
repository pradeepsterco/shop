<?php

namespace App\Policies;

use App\Models\User;
use App\Orders\Models\SellerOrder;

class SellerOrderPolicy
{
    /**
     * Determine whether the user can view the seller order.
     */
    public function view(User $user, SellerOrder $sellerOrder): bool
    {
        return $sellerOrder->seller_profile_id !== null
            && (int) $user->sellerProfile?->id === (int) $sellerOrder->seller_profile_id;
    }

    /**
     * Determine whether the user can update (confirm, pack, ship, deliver) the seller order.
     */
    public function update(User $user, SellerOrder $sellerOrder): bool
    {
        return $sellerOrder->seller_profile_id !== null
            && (int) $user->sellerProfile?->id === (int) $sellerOrder->seller_profile_id;
    }

    /**
     * Determine whether the user can cancel the seller order.
     */
    public function cancel(User $user, SellerOrder $sellerOrder): bool
    {
        return $sellerOrder->seller_profile_id !== null
            && (int) $user->sellerProfile?->id === (int) $sellerOrder->seller_profile_id;
    }
}
