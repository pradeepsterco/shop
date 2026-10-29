<?php

namespace App\Returns\Models;

use App\Returns\Enums\CourierClaimStatus;
use App\Returns\Enums\CourierClaimType;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $id
 * @property string $claim_number
 * @property string $shipment_id
 * @property string $seller_order_id
 * @property string $carrier_name
 * @property CourierClaimType $claim_type
 * @property int $claim_amount_paise
 * @property string $bearer
 * @property CourierClaimStatus $status
 * @property int|null $admin_id
 * @property string|null $carrier_claim_reference
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CourierClaim extends Model
{
    use HasUlids;

    protected $table = 'courier_claims';

    protected $fillable = [
        'id',
        'claim_number',
        'shipment_id',
        'seller_order_id',
        'carrier_name',
        'claim_type',
        'claim_amount_paise',
        'bearer',
        'status',
        'admin_id',
        'carrier_claim_reference',
        'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'claim_type' => CourierClaimType::class,
            'status' => CourierClaimStatus::class,
            'claim_amount_paise' => 'integer',
            'admin_id' => 'integer',
        ];
    }
}
