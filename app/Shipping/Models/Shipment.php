<?php

namespace App\Shipping\Models;

use App\Shipping\Enums\ShipmentDirection;
use App\Shipping\Enums\ShipmentPurpose;
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
 * @property ShipmentDirection $direction
 * @property ShipmentPurpose $purpose
 * @property string|null $parent_shipment_id
 * @property string $provider
 * @property string|null $courier_name
 * @property string|null $awb
 * @property string|null $vendor_wbn
 * @property string|null $provider_order_id
 * @property string|null $provider_shipment_id
 * @property int $fulfillment_attempt
 * @property string $creation_step
 * @property string $booking_status
 * @property string|null $booking_error
 * @property string|null $return_reason
 * @property string|null $public_tracking_url
 * @property ShipmentStatus $status
 * @property int $ndr_attempt_count
 * @property string|null $latest_ndr_reason
 * @property string|null $ndr_updated_phone
 * @property string|null $ndr_updated_address
 * @property string|null $ndr_action
 * @property Carbon|null $ndr_actioned_at
 * @property array<string, mixed>|null $partial_delivery_data
 * @property int $actual_weight_grams
 * @property int $volumetric_weight_grams
 * @property int $chargeable_weight_grams
 * @property int $length_cm
 * @property int $width_cm
 * @property int $height_cm
 * @property bool $is_cod
 * @property int $cod_amount_paise
 * @property int|null $declared_value_paise
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
 * @property-read Collection<int, ShipmentItem> $items
 */
class Shipment extends Model
{
    use HasUlids;

    protected $table = 'shipments';

    protected $attributes = [
        'direction' => 'forward',
        'purpose' => 'original',
        'booking_status' => self::BOOKING_BOOKED,
    ];

    public const BOOKING_PENDING = 'pending';

    public const BOOKING_IN_PROGRESS = 'in_progress';

    public const BOOKING_BOOKED = 'booked';

    public const BOOKING_FAILED = 'failed';

    protected $fillable = [
        'id',
        'seller_order_id',
        'order_id',
        'seller_profile_id',
        'direction',
        'purpose',
        'parent_shipment_id',
        'provider',
        'courier_name',
        'awb',
        'vendor_wbn',
        'provider_order_id',
        'provider_shipment_id',
        'fulfillment_attempt',
        'creation_step',
        'booking_status',
        'booking_error',
        'return_reason',
        'public_tracking_url',
        'status',
        'ndr_attempt_count',
        'latest_ndr_reason',
        'ndr_updated_phone',
        'ndr_updated_address',
        'ndr_action',
        'ndr_actioned_at',
        'partial_delivery_data',
        'actual_weight_grams',
        'volumetric_weight_grams',
        'chargeable_weight_grams',
        'length_cm',
        'width_cm',
        'height_cm',
        'is_cod',
        'cod_amount_paise',
        'declared_value_paise',
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
            'direction' => ShipmentDirection::class,
            'purpose' => ShipmentPurpose::class,
            'fulfillment_attempt' => 'integer',
            'status' => ShipmentStatus::class,
            'ndr_attempt_count' => 'integer',
            'ndr_actioned_at' => 'datetime',
            'partial_delivery_data' => 'array',
            'actual_weight_grams' => 'integer',
            'volumetric_weight_grams' => 'integer',
            'chargeable_weight_grams' => 'integer',
            'length_cm' => 'integer',
            'width_cm' => 'integer',
            'height_cm' => 'integer',
            'is_cod' => 'boolean',
            'cod_amount_paise' => 'integer',
            'declared_value_paise' => 'integer',
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

    /**
     * Items carried by this shipment; empty means the whole seller order (original forward shipments).
     *
     * @return HasMany<ShipmentItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(ShipmentItem::class, 'shipment_id');
    }

    public function isBooked(): bool
    {
        return $this->booking_status === self::BOOKING_BOOKED;
    }

    public function isReverse(): bool
    {
        return $this->direction === ShipmentDirection::Reverse;
    }

    public function statusLabel(): string
    {
        return $this->status->labelFor($this->direction);
    }

    /**
     * Valid next courier statuses for this shipment's direction (simulation offers and enforces these).
     *
     * @return list<ShipmentStatus>
     */
    public function nextCourierStatuses(): array
    {
        $next = $this->status->nextStatusesFor($this->direction);

        // A reship already carries only the undelivered units; it is delivered, returned or lost as a whole
        if ($this->purpose === ShipmentPurpose::Reship) {
            $next = array_values(array_filter($next, fn (ShipmentStatus $s) => $s !== ShipmentStatus::PartialDelivered));
        }

        return $next;
    }
}
