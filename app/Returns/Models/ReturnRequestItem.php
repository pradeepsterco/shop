<?php

namespace App\Returns\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property string $return_request_id
 * @property int $order_item_id
 * @property int $units
 * @property int $line_qty
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ReturnRequestItem extends Model
{
    protected $table = 'return_request_items';

    protected $fillable = [
        'return_request_id',
        'order_item_id',
        'units',
        'line_qty',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_item_id' => 'integer',
            'units' => 'integer',
            'line_qty' => 'integer',
        ];
    }
}
