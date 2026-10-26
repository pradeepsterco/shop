<?php

namespace App\Fees\Models;

use App\Shared\Enums\ShippingZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $fee_schedule_id
 * @property int $min_weight_grams
 * @property int|null $max_weight_grams
 * @property ShippingZone $zone
 * @property int $fee_amount
 * @property bool $is_default
 * @property-read FeeSchedule $schedule
 */
class FeeShippingSlab extends Model
{
    protected $table = 'fee_shipping_slabs';

    protected $fillable = [
        'fee_schedule_id',
        'min_weight_grams',
        'max_weight_grams',
        'zone',
        'fee_amount',
        'is_default',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee_schedule_id' => 'integer',
            'min_weight_grams' => 'integer',
            'max_weight_grams' => 'integer',
            'zone' => ShippingZone::class,
            'fee_amount' => 'integer',
            'is_default' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<FeeSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(FeeSchedule::class, 'fee_schedule_id');
    }
}
