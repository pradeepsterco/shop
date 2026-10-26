<?php

namespace App\Fees\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $fee_schedule_id
 * @property int|null $vertical_id
 * @property int $min_price
 * @property int|null $max_price
 * @property int $rate_bps
 * @property-read FeeSchedule $schedule
 */
class FeeCommissionSlab extends Model
{
    protected $table = 'fee_commission_slabs';

    protected $fillable = [
        'fee_schedule_id',
        'vertical_id',
        'min_price',
        'max_price',
        'rate_bps',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee_schedule_id' => 'integer',
            'vertical_id' => 'integer',
            'min_price' => 'integer',
            'max_price' => 'integer',
            'rate_bps' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<FeeSchedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(FeeSchedule::class, 'fee_schedule_id');
    }

    public function percentageRate(): float
    {
        return $this->rate_bps / 100.0;
    }
}
