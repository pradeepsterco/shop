<?php

namespace App\Policies;

use App\Models\CustomerAddress;
use App\Models\User;

class CustomerAddressPolicy
{
    /**
     * Determine whether the user can view the customer address.
     */
    public function view(User $user, CustomerAddress $address): bool
    {
        return (int) $user->id === (int) $address->user_id;
    }

    /**
     * Determine whether the user can update the customer address.
     */
    public function update(User $user, CustomerAddress $address): bool
    {
        return (int) $user->id === (int) $address->user_id;
    }

    /**
     * Determine whether the user can delete the customer address.
     */
    public function delete(User $user, CustomerAddress $address): bool
    {
        return (int) $user->id === (int) $address->user_id;
    }
}
