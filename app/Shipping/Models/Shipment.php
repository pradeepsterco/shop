<?php

namespace App\Shipping\Models;

use App\Shipping\Enums\ShipmentStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property string $id
 * @property string $seller_order_id
 * @property string $order_id
 * @property int $seller_profile_id
 * @property string $provider
 * @property string|null $courier_name
 * @property string|null $awb
 * @property string|null $vendor_wbn
 * @property string|null $provider_order_id
 * @property string|null $provider_shipment_id
 * @property int $fulfillment_attempt
 * @property string $creation_step
 * @property string|null $public_tracking_url
 * @property ShipmentStatus $status
 * @property int $actual_weight_grams
 * @property int $volumetric_weight_grams
 * @property int $chargeable_weight_grams
 * @property int $length_cm
 * @property int $width_cm
 * @property int $height_cm
 * @property bool $is_cod
 * @property int $cod_amount_paise
 * @property string|null $label_path
 * @property string|null $manifest_path
 * @property Carbon|null $label_generated_at
 * @property Carbon|null $pickup_date
 * @property Carbon|null $picked_up_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $cancelled_at
 * @property int $estimated_carrier_charges_paise
 * @property int $actual_carrier_charges_paise
 * @property int|null $carrier_tat_min_days
 * @property int|null $carrier_tat_max_days
 * @property Carbon|null $last_polled_at
 * @property array<string, mixed>|null $raw_metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Collection<int, ShipmentEvent> $events
 */
class Shipment extends Model
{
    use HasUlids;

    protected $table = 'shipments';

    protected $fillable = [
        'id',
        'seller_order_id',
        'order_id',
        'seller_profile_id',
        'provider',
        'courier_name',
        'awb',
        'vendor_wbn',
        'provider_order_id',
        'provider_shipment_id',
        'fulfillment_attempt',
        'creation_step',
        'public_tracking_url',
        'status',
        'actual_weight_grams',
        'volumetric_weight_grams',
        'chargeable_weight_grams',
        'length_cm',
        'width_cm',
        'height_cm',
        'is_cod',
        'cod_amount_paise',
        'label_path',
        'manifest_path',
        'label_generated_at',
        'pickup_date',
        'picked_up_at',
        'delivered_at',
        'cancelled_at',
        'estimated_carrier_charges_paise',
        'actual_carrier_charges_paise',
        'carrier_tat_min_days',
        'carrier_tat_max_days',
        'last_polled_at',
        'raw_metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'seller_profile_id' => 'integer',
            'fulfillment_attempt' => 'integer',
            'status' => ShipmentStatus::class,
            'actual_weight_grams' => 'integer',
            'volumetric_weight_grams' => 'integer',
            'chargeable_weight_grams' => 'integer',
            'length_cm' => 'integer',
            'width_cm' => 'integer',
            'height_cm' => 'integer',
            'is_cod' => 'boolean',
            'cod_amount_paise' => 'integer',
            'estimated_carrier_charges_paise' => 'integer',
            'actual_carrier_charges_paise' => 'integer',
            'carrier_tat_min_days' => 'integer',
            'carrier_tat_max_days' => 'integer',
            'label_generated_at' => 'datetime',
            'pickup_date' => 'date',
            'picked_up_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_polled_at' => 'datetime',
            'raw_metadata' => 'array',
        ];
    }

    /**
     * @return HasMany<ShipmentEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(ShipmentEvent::class, 'shipment_id')->orderBy('occurred_at', 'desc');
    }
}
