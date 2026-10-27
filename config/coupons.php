<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Coupon Feature Switch
    |--------------------------------------------------------------------------
    |
    | When disabled, coupon inputs are hidden from checkout and coupon evaluations
    | are rejected cleanly across all services without removing the module.
    | Defaults to false until tax/accounting review is signed off.
    |
    */
    'enabled' => (bool) env('COUPONS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Minimum Post-Discount Items Total (in Paise)
    |--------------------------------------------------------------------------
    |
    | Prevents 0-payable orders by capping the maximum discount so items total
    | remains at least this amount (default 100 paise = ₹1.00).
    |
    */
    'min_payable_paise' => (int) env('COUPONS_MIN_PAYABLE_PAISE', 100),

    /*
    |--------------------------------------------------------------------------
    | Restore Usage on Returned to Origin (RTO)
    |--------------------------------------------------------------------------
    |
    | When false (default), orders marked Returned to Origin (RTO) do not restore
    | the customer's per-user usage or the coupon's global usage count, preventing promo abuse.
    | When true, RTO releases the coupon redemption and restores usage limits.
    |
    */
    'restore_usage_on_rto' => (bool) env('COUPONS_RESTORE_USAGE_ON_RTO', false),
];
