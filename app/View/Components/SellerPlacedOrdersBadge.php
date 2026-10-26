<?php

namespace App\View\Components;

use App\Models\User;
use App\Orders\Enums\SellerOrderStatus;
use App\Orders\Models\SellerOrder;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class SellerPlacedOrdersBadge extends Component
{
    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View
    {
        $user = auth()->user();
        $seller = ($user instanceof User) ? $user->sellerProfile : null;

        $count = $seller
            ? SellerOrder::where('seller_profile_id', $seller->id)
                ->where('status', SellerOrderStatus::Placed)
                ->count()
            : 0;

        return view('components.seller-placed-orders-badge', compact('count'));
    }
}
