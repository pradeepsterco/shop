<?php

namespace App\Orders\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $seller_order_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string $changed_by_type
 * @property int|null $changed_by_id
 * @property string|null $reason
 * @property Carbon $created_at
 * @property-read SellerOrder $sellerOrder
 */
class OrderStatusHistory extends Model
{
    public $timestamps = false;

    protected $table = 'order_status_histories';

    protected $fillable = [
        'seller_order_id',
        'from_status',
        'to_status',
        'changed_by_type',
        'changed_by_id',
        'reason',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SellerOrder, $this>
     */
    public function sellerOrder(): BelongsTo
    {
        return $this->belongsTo(SellerOrder::class, 'seller_order_id');
    }
}
