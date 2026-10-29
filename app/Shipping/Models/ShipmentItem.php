<?php

namespace App\Shipping\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An order item (and number of units) carried by a reship, return pickup or replacement shipment.
 *
 * @property int $id
 * @property string $shipment_id
 * @property int $order_item_id
 * @property int $qty
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shipment $shipment
 */
class ShipmentItem extends Model
{
    protected $table = 'shipment_items';

    protected $fillable = [
        'shipment_id',
        'order_item_id',
        'qty',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_item_id' => 'integer',
            'qty' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Shipment, $this>
     */
    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class, 'shipment_id');
    }
}
