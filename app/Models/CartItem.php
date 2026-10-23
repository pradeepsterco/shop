<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CartItem extends Model
{
    protected $guarded = [];

    protected $casts = [
        'qty'             => 'integer',
        'unit_price'      => 'integer',
        'mrp'             => 'integer',
        'prev_unit_price' => 'integer',
        'available_qty'   => 'integer',
        'synced_at'       => 'datetime',
    ];

    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }
}