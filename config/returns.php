<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Seller RTO Inspection Window
    |--------------------------------------------------------------------------
    | Number of days a seller has to report the received condition of an RTO package.
    */
    'seller_rto_inspection_days' => (int) env('RETURNS_SELLER_RTO_INSPECTION_DAYS', 3),

    /*
    |--------------------------------------------------------------------------
    | Courier Loss Bearer
    |--------------------------------------------------------------------------
    | Who absorbs the item value when a shipment is marked Lost by the courier.
    | Options: 'platform', 'seller', 'courier'
    */
    'courier_loss_bearer' => env('RETURNS_COURIER_LOSS_BEARER', 'platform'),

    /*
    |--------------------------------------------------------------------------
    | RTO Shipping Multiplier
    |--------------------------------------------------------------------------
    | Multiplier applied to forward shipping fee to calculate the RTO shipping debit.
    */
    'rto_shipping_multiplier' => (float) env('RETURNS_RTO_SHIPPING_MULTIPLIER', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Reship Shipping Multiplier
    |--------------------------------------------------------------------------
    | Reship of undelivered items: the seller is charged the forward shipping originally allocated to
    | those units times this multiplier, when the reship is created.
    */
    'reship_shipping_multiplier' => (float) env('RETURNS_RESHIP_SHIPPING_MULTIPLIER', 1.0),

    /*
    |--------------------------------------------------------------------------
    | Return Windows
    |--------------------------------------------------------------------------
    | Days after delivery a customer may request a return. Each vertical sets its own window
    | (verticals.return_window_days); verticals without one use the default. Non-returnable
    | verticals still accept damaged/defective/wrong/missing claims for claim_window_days.
    | A package's settlement hold is the longest window among its items.
    */
    'default_return_window_days' => (int) env('RETURNS_DEFAULT_WINDOW_DAYS', 7),
    'claim_window_days' => (int) env('RETURNS_CLAIM_WINDOW_DAYS', 7),

    /*
    |--------------------------------------------------------------------------
    | Customer Returns (B6)
    |--------------------------------------------------------------------------
    | return_report_days: days after the return parcel reaches the seller to submit a condition report.
    | return_shipping_multiplier: reverse pickup charged to the seller = forward shipping allocated to the
    | returned units x this. replacement_shipping_multiplier: same for a replacement or a ship-back to the
    | customer. commission_on_return: whether the seller's marketplace fees are reversed with the sale,
    | by fault (keep = the seller still pays them).
    */
    'return_report_days' => (int) env('RETURNS_REPORT_DAYS', 3),
    'return_shipping_multiplier' => (float) env('RETURNS_RETURN_SHIPPING_MULTIPLIER', 1.0),
    'replacement_shipping_multiplier' => (float) env('RETURNS_REPLACEMENT_SHIPPING_MULTIPLIER', 1.0),
    'commission_on_return' => [
        'seller_fault' => 'keep',
        'customer_remorse' => 'reverse',
        'courier_fault' => 'reverse',
    ],
];
