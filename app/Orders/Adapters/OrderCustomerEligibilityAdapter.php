<?php

namespace App\Orders\Adapters;

use App\Coupons\Contracts\CustomerEligibilityInterface;
use App\Orders\Enums\OrderStatus;
use App\Orders\Models\Order;

class OrderCustomerEligibilityAdapter implements CustomerEligibilityInterface
{
    public function isFirstOrder(string $customerReference): bool
    {
        if ($customerReference === '' || ! is_numeric($customerReference)) {
            return false;
        }

        $userId = (int) $customerReference;

        // A customer has no prior orders if there are no non-cancelled orders for their user ID
        return ! Order::query()
            ->where('user_id', $userId)
            ->whereNotIn('status', [OrderStatus::Cancelled])
            ->exists();
    }
}
