<?php

namespace App\Returns\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $loss_type
 * @property string $seller_order_id
 * @property string $shipment_id
 * @property string|null $courier_claim_id
 * @property int $loss_amount_paise
 * @property int $declared_value_paise
 * @property int|null $admin_id
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PlatformLoss extends Model
{
    use HasUlids;

    public const TYPE_LOST_SHIPMENT = 'lost_shipment';

    public const TYPE_LOST_RETURN = 'lost_return';

    /** Refund given after a failed return check without blaming the seller. */
    public const TYPE_GOODWILL_REFUND = 'goodwill_refund';

    protected $table = 'platform_losses';

    protected $fillable = [
        'id',
        'loss_type',
        'seller_order_id',
        'shipment_id',
        'courier_claim_id',
        'loss_amount_paise',
        'declared_value_paise',
        'admin_id',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'loss_amount_paise' => 'integer',
            'declared_value_paise' => 'integer',
            'admin_id' => 'integer',
        ];
    }
}
