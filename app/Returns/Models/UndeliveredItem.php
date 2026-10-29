<?php

namespace App\Returns\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * Units of an order line the courier did not deliver on a partial delivery. The admin decides once:
 * refund, or reship (which can come back to the queue if the reship is returned or cancelled).
 *
 * @property string $id
 * @property string $seller_order_id
 * @property string $shipment_id
 * @property int $order_item_id
 * @property int $units
 * @property int $line_qty
 * @property string $status
 * @property string|null $resolution
 * @property string|null $refund_id
 * @property string|null $reship_shipment_id
 * @property int $reship_attempts
 * @property Carbon|null $resolved_at
 * @property int|null $resolved_by_admin_id
 * @property int|null $recorded_by_admin_id
 * @property string $origin
 * @property bool $customer_paid
 * @property string|null $return_request_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UndeliveredItem extends Model
{
    use HasUlids;

    public const STATUS_PENDING = 'pending';

    public const STATUS_REFUNDED = 'refunded';

    public const STATUS_RESHIPPING = 'reshipping';

    public const STATUS_RESHIPPED = 'reshipped';

    public const STATUS_LOST = 'lost';

    public const ORIGIN_PARTIAL_DELIVERY = 'partial_delivery';

    public const ORIGIN_MISSING_ITEM_CLAIM = 'missing_item_claim';

    public const RESOLUTION_REFUND = 'refund';

    public const RESOLUTION_RESHIP = 'reship';

    /** Refund source type for these items (one refund per undelivered item). */
    public const REFUND_SOURCE = 'undelivered_item';

    protected $table = 'undelivered_items';

    protected $fillable = [
        'id',
        'seller_order_id',
        'shipment_id',
        'order_item_id',
        'units',
        'line_qty',
        'status',
        'resolution',
        'refund_id',
        'reship_shipment_id',
        'reship_attempts',
        'resolved_at',
        'resolved_by_admin_id',
        'recorded_by_admin_id',
        'origin',
        'customer_paid',
        'return_request_id',
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
            'reship_attempts' => 'integer',
            'resolved_at' => 'datetime',
            'resolved_by_admin_id' => 'integer',
            'recorded_by_admin_id' => 'integer',
            'customer_paid' => 'boolean',
        ];
    }

    /**
     * @return array<int, array{units: int, line_qty: int}>
     */
    public function lines(): array
    {
        return [$this->order_item_id => ['units' => $this->units, 'line_qty' => $this->line_qty]];
    }
}
