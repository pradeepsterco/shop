<?php

namespace App\Fees\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $fee_schedule_id
 * @property string $payment_method
 * @property int $rate_bps
 * @property int $fee_amount
 * @property-read FeeSchedule $schedule
 */
class FeeCollectionRule extends Model
{
    protected $table = 'fee_collection_rules';

    protected $fillable = [
        'fee_schedule_id',
        'payment_method',
        'rate_bps',
        'fee_amount',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee_schedule_id' => 'integer',
            'rate_bps' => 'integer',
            'fee_amount' => 'integer',
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
