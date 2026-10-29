<?php

namespace App\Orders\Contracts;

interface SellerOrderReturnWindowLookupInterface
{
    /**
     * Days after delivery during which the package can still come back: the longest window among its items.
     * Returnable vertical: its return window (or returns.default_return_window_days);
     * non-returnable vertical: returns.claim_window_days (damaged/defective/wrong/missing claims).
     */
    public function returnWindowDays(string $sellerOrderId): int;
}
