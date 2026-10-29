<?php

namespace App\Shipping\Models;

use App\Shipping\Enums\ShipmentEventType;
use App\Shipping\Enums\ShipmentStatus;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $shipment_id
 * @property string $event_key
 * @property ShipmentEventType $event_type
 * @property string $provider
 * @property ShipmentStatus $status
 * @property string $provider_status
 * @property string|null $provider_ndr_status
 * @property string|null $location
 * @property string|null $description
 * @property Carbon|null $occurred_at
 * @property Carbon $received_at
 * @property array<string, mixed>|null $raw_payload
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shipment $shipment
 */
class ShipmentEvent extends Model
{
    use HasUlids;

    protected $table = 'shipment_events';

    protected $attributes = [
        'event_type' => 'courier_update',
    ];

    protected $fillable = [
        'id',
        'shipment_id',
        'event_key',
        'event_type',
        'provider',
        'status',
        'provider_status',
        'provider_ndr_status',
        'location',
        'description',
        'occurred_at',
        'received_at',
        'raw_payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => ShipmentEventType::class,
            'status' => ShipmentStatus::class,
            'occurred_at' => 'datetime',
            'received_at' => 'datetime',
            'raw_payload' => 'array',
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
