<?php

namespace App\Policies;

use App\Models\User;
use App\Orders\Models\Order;

class OrderPolicy
{
    /**
     * Determine whether the user can view the order.
     */
    public function view(User $user, Order $order): bool
    {
        return (int) $user->id === (int) $order->user_id;
    }

    /**
     * Determine whether the user can cancel a package in the order.
     */
    public function cancel(User $user, Order $order): bool
    {
        return (int) $user->id === (int) $order->user_id;
    }
}
